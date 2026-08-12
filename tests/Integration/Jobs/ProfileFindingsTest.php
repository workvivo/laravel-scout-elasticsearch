<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Matchish\ScoutElasticSearch\Console\Commands\ImportCommand;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\ProfileDiagnostics;
use Matchish\ScoutElasticSearch\Jobs\PullChunkJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fakes\FakeImportRunStore;
use Tests\IntegrationTestCase;

/**
 * The worker -> terminal pipe for `--profile-samples` findings.
 *
 * Profiling computes everything an operator needs and then writes it to the
 * log of whichever queue worker happened to run the chunk, on whichever host —
 * which is the one place the operator who typed `scout:import` is not looking.
 * The fix routes DIAGNOSES (not metric dumps) through the run record, the single
 * channel that already flows back towards the terminal, where
 * `--parallel --wait` polls it.
 *
 * Three properties are worth a test each, and they matter in this order:
 *
 *  1. A DIAGNOSTIC MUST NEVER FAIL AN IMPORT. Publication happens after the
 *     chunk's documents are already in the index, so anything that goes wrong
 *     from that point on may cost the operator a diagnosis and nothing else.
 *     {@see a_store_that_cannot_record_a_finding_must_not_fail_the_chunk} is the
 *     test this whole file exists for.
 *  2. It costs nothing when it is not wanted: an unprofiled chunk and a zero cap
 *     both perform no store writes whatsoever.
 *  3. It survives scale: 100k profiled chunks tripping the same n+1 collapse to
 *     one row carrying an occurrence count and the worst example seen.
 *
 * The findings are provoked with {@see LazyProduct}, a test-only Product variant
 * whose toSearchableArray() lazy-loads a relation — the classic N+1, and the one
 * finding that can be made deterministic. Timing-derived findings
 * (fetch/index dominance, timeout proximity) depend on how fast the machine and
 * the cluster are, so nothing here asserts on their presence or absence.
 */
final class ProfileFindingsTest extends IntegrationTestCase
{
    private const TOKEN = 'profile-findings-token';

    /** Chunk size is 3 (see TestCase), so 5 rows plan as 3 + 2. */
    private const PRODUCTS = 5;

    private const CHUNKS = 2;

    /**
     * A value that only ever exists as a QUERY BINDING, never as SQL: the
     * per-row custom_key the self-join relation is bound to. Any captured
     * statement containing it means bindings leaked into a log line and into the
     * run record, which is the one thing this feature may not do.
     */
    private const BINDING_SENTINEL = 'binding-sentinel-uuid';

    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    /** The MessageLogged listener is registered at most once per test. */
    private bool $listening = false;

    public function setUp(): void
    {
        parent::setUp();

        // SCOUT_QUEUE_TIMEOUT decides whether the two timeout rules can fire at
        // all: PullChunkJob passes it through as the job timeout, and
        // ProfileDiagnostics reads a null as "unknown" and stays silent rather
        // than guessing. Pinned to unset here so a stray value in the
        // environment cannot make the published set of findings depend on how
        // fast this machine happens to be.
        config()->set('elasticsearch.queue.timeout', null);
    }

    /**
     * @test
     */
    public function a_profiled_chunk_publishes_its_diagnoses_into_the_run_record(): void
    {
        Bus::fake();

        $store = $this->probeableStore();
        $this->products(self::PRODUCTS);

        $source = $this->source();
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        $this->chunkJob($source, $index, 0, true)->handle($this->elasticsearch);

        $findings = $store->profileFindings(self::TOKEN);
        $this->assertArrayHasKey(
            ProfileDiagnostics::CODE_N_PLUS_ONE,
            $findings,
            'A chunk that lazy-loaded a relation must publish the n+1 diagnosis, not just log its metrics.'
        );

        // The stored row carries the verdict plus exactly the figures that
        // justify it: which relation, how many loads, how many distinct
        // relations were involved.
        $nPlusOne = $findings[ProfileDiagnostics::CODE_N_PLUS_ONE];
        $this->assertSame(1, $nPlusOne['count']);
        $this->assertSame(3.0, $nPlusOne['weight'], 'The weight is the worst hit count seen, so rows can be ranked.');
        $this->assertSame(LazyProduct::class.'::variants', $nPlusOne['data']['relation']);
        $this->assertSame(3, $nPlusOne['data']['loads'], 'One lazy load per model in the chunk.');
        $this->assertSame(1, $nPlusOne['data']['relations']);

        // The chunk itself is untouched by any of it: the documents are indexed
        // and the chunk is accounted for.
        $this->assertTrue($store->isDone(self::TOKEN, 0));
        $this->assertSame(3, $this->indexedCount('products'));
    }

    /**
     * @test
     */
    public function an_unprofiled_chunk_writes_no_findings_at_all(): void
    {
        // The overwhelmingly common case, including on a --profile-samples run
        // where only a handful of chunks carry a profiling stage. It must not
        // cost a single round trip.
        Bus::fake();

        $store = $this->probeableStore();
        $this->products(self::PRODUCTS);

        $source = $this->source();
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        $this->chunkJob($source, $index, 0, false)->handle($this->elasticsearch);

        $this->assertSame([], $store->profileFindings(self::TOKEN));
        $this->assertSame(
            0,
            $store->recordCalls,
            'An unprofiled chunk has no metrics to interpret, so it must not reach the store at all.'
        );

        // Not a vacuous assertion: the chunk really did run.
        $this->assertTrue($store->isDone(self::TOKEN, 0));
        $this->assertSame(3, $this->indexedCount('products'));
    }

    /**
     * THE TEST THIS FILE EXISTS FOR.
     *
     * Publication only ever observes work that has ALREADY SUCCEEDED — the
     * documents are in the index before a single finding is recorded — so a
     * Redis script error, a drifted metrics array or a payload json_encode
     * chokes on must cost the operator a diagnosis and nothing more. If it were
     * allowed to propagate it would skip markDone(), and a healthy chunk would
     * come back as a failure that can roll back a multi-hour import.
     *
     * `isDone` is the exact discriminator: it is set after the publication call,
     * so it can only be true if the throw was swallowed.
     *
     * @test
     */
    public function a_store_that_cannot_record_a_finding_must_not_fail_the_chunk(): void
    {
        Bus::fake();

        $store = $this->probeableStore(true);
        $this->recordLogs();
        $this->products(self::PRODUCTS);

        $source = $this->source();
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        // Nothing is caught here on purpose: a throw escaping handle() fails
        // this test, which is precisely the regression being guarded.
        $this->chunkJob($source, $index, 0, true)->handle($this->elasticsearch);

        $this->assertGreaterThan(0, $store->recordCalls, 'The publication has to have been attempted.');
        $this->assertTrue(
            $store->isDone(self::TOKEN, 0),
            'A DIAGNOSTIC MUST NEVER FAIL AN IMPORT: the chunk indexed its documents, so it stays done even though the store rejected the finding.'
        );
        $this->assertSame(3, $this->indexedCount('products'));
        $this->assertSame([], $store->profileFindings(self::TOKEN));
        $this->assertSame(
            ImportRunStore::STATUS_RUNNING,
            $store->status(self::TOKEN),
            'A failed diagnostic may not touch the run status either.'
        );

        // Swallowed, but never silent: the operator can still find out that the
        // diagnosis they asked for was lost.
        $this->assertContains(
            'profile_publish_failed',
            $this->loggedClassifications(),
            'The swallowed failure must still be logged at warning level.'
        );
    }

    /**
     * @test
     */
    public function a_zero_findings_cap_disables_publication_entirely(): void
    {
        Bus::fake();

        $this->app['config']->set('elasticsearch.import.profile_findings', 0);

        $store = $this->probeableStore();
        $this->products(self::PRODUCTS);

        $source = $this->source();
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        $this->chunkJob($source, $index, 0, true)->handle($this->elasticsearch);

        $this->assertSame(
            0,
            $store->recordCalls,
            'SCOUT_IMPORT_PROFILE_FINDINGS=0 must cost zero store I/O, not merely produce zero rows.'
        );
        $this->assertSame([], $store->profileFindings(self::TOKEN));
        $this->assertTrue($store->isDone(self::TOKEN, 0));
    }

    /**
     * @test
     */
    public function findings_from_many_chunks_collapse_to_one_row_per_code(): void
    {
        // At PERF scale this is what keeps the run record small: 100k profiled
        // chunks all tripping the same n+1 have to end up as one row, or the
        // diagnostic becomes its own outage.
        Bus::fake();

        $store = $this->probeableStore();
        $this->products(self::PRODUCTS);

        $source = $this->source();
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        // The SMALLER chunk first (2 rows, so 2 lazy loads), then the larger one
        // (3). The worst example can only be reported correctly if the store
        // keeps the worst weight rather than the most recent one.
        $this->chunkJob($source, $index, 1, true)->handle($this->elasticsearch);
        $this->chunkJob($source, $index, 0, true)->handle($this->elasticsearch);

        $findings = $store->profileFindings(self::TOKEN);
        $this->assertArrayHasKey(ProfileDiagnostics::CODE_N_PLUS_ONE, $findings);

        $nPlusOne = $findings[ProfileDiagnostics::CODE_N_PLUS_ONE];
        $this->assertSame(2, $nPlusOne['count'], 'Two chunks tripped the same n+1: one row, occurrence count 2.');
        $this->assertSame(3.0, $nPlusOne['weight']);
        $this->assertSame(
            3,
            $nPlusOne['data']['loads'],
            'The kept example must be the worst chunk (3 loads), not the last one published (2).'
        );

        $this->assertGreaterThanOrEqual(2, $store->recordCalls, 'Both chunks published.');
        $this->assertSame(2, count($store->doneChunks(self::TOKEN)));
        $this->assertSame(self::PRODUCTS, $this->indexedCount('products'));
    }

    /**
     * @test
     */
    public function the_wait_terminal_prints_the_findings_without_changing_the_exit_code(): void
    {
        // The whole point of GAP A, end to end. Sync executes queued jobs inline,
        // so the prepare chain, the fan-out and every chunk job — publication
        // included — run in this process before --wait reads the run record;
        // --force is required precisely because the connection is sync.
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('scout:import', [
            'searchable' => [LazyProduct::class],
            '--parallel' => true,
            '--force' => true,
            '--wait' => true,
            '--profile-samples' => 'all',
        ], $output);

        $this->assertSame(
            ImportCommand::SUCCESS,
            $exitCode,
            'A finding is a diagnosis, never an outcome: an import that succeeded still exits 0.'
        );

        $text = $output->fetch();

        // The diagnosis, the figures behind it, how many chunks hit it, and the
        // remedy — all in front of the operator who typed the command, none of it
        // requiring them to go and tail a worker's log.
        $this->assertStringContainsString('N+1 while indexing', $text);
        $this->assertStringContainsString(LazyProduct::class.'::variants', $text);
        $this->assertStringContainsString('2 chunk(s)', $text);
        $this->assertStringContainsString('makeAllSearchableUsing', $text);

        // A published payload is rendered through the translations, so neither a
        // raw lang key nor an unreplaced placeholder may reach the terminal.
        $this->assertStringNotContainsString('scout::import.', $text);
        $this->assertStringNotContainsString(':loads', $text);
        $this->assertStringNotContainsString(':relation', $text);

        $this->assertSame(
            self::PRODUCTS,
            $this->indexedCount((new LazyProduct())->searchableAs()),
            'Everything was still indexed while the findings were being collected.'
        );
    }

    /**
     * @test
     */
    public function a_wait_run_without_profiling_says_nothing_about_findings(): void
    {
        // The polling gate: without profiling no worker writes anything, so
        // --wait must not spend a round trip per poll looking, and must not
        // change one character of its existing output.
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->products(self::PRODUCTS);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('scout:import', [
            'searchable' => [LazyProduct::class],
            '--parallel' => true,
            '--force' => true,
            '--wait' => true,
        ], $output);

        $text = $output->fetch();

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('Profiling findings', $text);
        $this->assertStringNotContainsString('N+1 while indexing', $text);
        $this->assertSame(self::PRODUCTS, $this->indexedCount((new LazyProduct())->searchableAs()));
    }

    /**
     * @test
     */
    public function a_finding_is_announced_while_the_import_is_still_running_and_only_once(): void
    {
        // The inline path, which is where a diagnosis is worth the most: the
        // operator can still stop a 6-hour import that is lazy-loading a relation
        // per model. It cannot be reached through the sync driver (everything has
        // already finished by the time --wait polls), so the run record is seeded
        // by hand and the store is scripted to report one RUNNING poll followed by
        // a terminal one — the smallest run that has a "during" at all.
        $store = $this->probeableStore();
        $token = 'wait-render-token';

        $store->start($token, 4, 'products_20240101');
        $store->recordProfileFinding(
            $token,
            ProfileDiagnostics::CODE_N_PLUS_ONE,
            480.0,
            (string) json_encode(['relation' => 'App\\Order::user', 'loads' => 480, 'relations' => 2]),
            900,
            20
        );
        $store->scriptStatuses(ImportRunStore::STATUS_RUNNING, ImportRunStore::STATUS_SUCCEEDED);

        $buffer = new BufferedOutput();
        $command = $this->commandWithOptions(['--profile-samples' => 'all'], $buffer);

        $waitForRun = new ReflectionMethod(ImportCommand::class, 'waitForRun');
        $waitForRun->setAccessible(true);
        $exitCode = $waitForRun->invoke($command, 'App\Order', $token, 'redis', 'reindex');

        $this->assertSame(ImportCommand::SUCCESS, $exitCode, 'Rendering a finding may not change the exit code.');

        $text = $buffer->fetch();

        // The diagnosis, with the numbers behind it, and the remedy.
        $this->assertStringContainsString('N+1 while indexing [App\Order]', $text);
        $this->assertStringContainsString('App\Order::user was lazy-loaded 480 times', $text);
        $this->assertStringContainsString('eager-load the relation in makeAllSearchableUsing()', $text);

        // Announced during the run, before the closing roll-up — not only after
        // the import is already over.
        $this->assertLessThan(
            (int) strpos($text, 'Profiling findings for'),
            (int) strpos($text, 'N+1 while indexing'),
            'The finding must reach the terminal while the run is still going, ahead of the summary.'
        );

        // The remedy is printed when a code first appears and never again, so a
        // code recurring across thousands of chunks cannot bury the progress
        // output under repeats of its own fix.
        $this->assertSame(
            1,
            substr_count($text, 'eager-load the relation in makeAllSearchableUsing()'),
            'A code already announced inline must not have its remedy repeated by the summary.'
        );

        // The roll-up still reports it, with how many chunks hit it.
        $this->assertStringContainsString('Profiling findings for [App\Order] (1 distinct', $text);
        $this->assertStringContainsString('1 chunk(s): N+1 while indexing', $text);
        $this->assertStringNotContainsString('scout::import.', $text);
    }

    /**
     * @test
     */
    public function a_profiled_chunk_logs_the_slowest_query_of_each_phase(): void
    {
        // The capture end of the feature, end to end against a real database.
        // `queries: 5` tells an operator that the fetch ran five statements and
        // nothing about WHICH of them burned 258 seconds — the query log already
        // carried both the SQL and its duration and threw them away. This asserts
        // they now survive into the one place a worker can publish anything: the
        // `scout:import chunk profile` log line.
        Bus::fake();

        $store = $this->probeableStore();
        $this->recordLogs();
        $this->productsWithSentinelKeys(self::PRODUCTS);

        $source = $this->source();
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        $this->chunkJob($source, $index, 0, true)->handle($this->elasticsearch);

        $context = $this->profileLogContext();

        $this->assertArrayHasKey(
            'slow_query',
            $context,
            'A profiled chunk must publish the slowest statement of each phase, not only a query count.'
        );

        $slow = $context['slow_query'];
        $this->assertSame(
            ['fetch', 'filter', 'index'],
            array_keys($slow),
            'The phases are published in the order they run: every renderer iterates these keys.'
        );

        // The contract is a biconditional — a phase reports null exactly when it
        // issued no query — so it is asserted as one, against the counts sitting
        // right next to it in the same log line.
        foreach (['fetch', 'filter', 'index'] as $phase) {
            if ($context['queries'][$phase] === 0) {
                $this->assertNull($slow[$phase], "The $phase phase ran no query, so it has no slowest one.");

                continue;
            }

            $this->assertIsArray($slow[$phase], "The $phase phase ran a query, so its slowest one must be reported.");
            $this->assertSame(
                ['sql', 'ms'],
                array_keys($slow[$phase]),
                'A captured entry is the statement and its duration and NOTHING else — in particular no bindings.'
            );
            $this->assertIsFloat($slow[$phase]['ms']);
            $this->assertGreaterThanOrEqual(0.0, $slow[$phase]['ms']);
            $this->assertStringNotContainsString(
                "\n",
                $slow[$phase]['sql'],
                'Whitespace is normalised so one query stays one log line.'
            );
        }

        // The fetch is the phase the whole feature was built for, and it is real
        // SQL from the real keyset chunk query, not a placeholder or a label.
        $fetch = $slow['fetch'];
        $this->assertIsArray($fetch);
        $this->assertStringContainsString('select', $fetch['sql']);
        $this->assertStringContainsString('products', $fetch['sql']);
        $this->assertStringContainsString('?', $fetch['sql'], 'The statement keeps its `?` placeholders.');

        // shouldBeSearchable() decides from an attribute that is already loaded,
        // which is the healthy shape — and therefore the null case.
        $this->assertSame(0, $context['queries']['filter']);
        $this->assertNull($slow['filter']);

        // The index phase resolved LazyProduct::variants once per model, so its
        // slowest statement is that lazy load: a parameterised self-join on
        // custom_key whose BINDING IS THE SENTINEL VALUE seeded above.
        $indexSlow = $slow['index'];
        $this->assertIsArray($indexSlow);
        $this->assertStringContainsString('custom_key', $indexSlow['sql']);
        $this->assertStringContainsString('?', $indexSlow['sql']);

        // PRIVACY, the requirement this feature is constrained by: the statement
        // travels into application logs and into the Redis run record, so it may
        // carry `?` and never the value behind it. The assertion is not vacuous —
        // the index-phase statement asserted just above is exactly the query whose
        // binding was self::BINDING_SENTINEL.
        $encoded = (string) json_encode($slow);
        $this->assertStringNotContainsString(
            self::BINDING_SENTINEL,
            $encoded,
            'A BOUND VALUE REACHED THE OUTPUT. Bindings are row data (emails, names, tokens) and must never be captured.'
        );
        $this->assertStringNotContainsString(
            'bindings',
            $encoded,
            'The bindings array must not be carried along even under its own key.'
        );

        // And none of it changed what the chunk did.
        $this->assertTrue($store->isDone(self::TOKEN, 0));
        $this->assertSame(3, $this->indexedCount('products'));
    }

    /**
     * @test
     */
    public function a_captured_statement_collapses_its_placeholder_runs_and_still_names_the_query(): void
    {
        // The reason capture normalises before truncating. An eager load over a
        // 1000-row chunk logs `in (?, ?, ?, … x1000)` — several kilobytes of
        // nothing — and a naive truncation would hand the operator a wall of
        // question marks with the tables and joins, the entire diagnostic value,
        // cut off the end.
        //
        // EagerProduct makes this deterministic without depending on which of the
        // fetch phase's two statements happened to be slower on this machine:
        // BOTH carry a collapsible run (the base query's `not in (?, ?, ?, ?)`
        // guard, the eager load's `in (?, ?, ?)`), so whichever was picked has to
        // come back collapsed.
        Bus::fake();

        $store = $this->probeableStore();
        $this->recordLogs();
        $this->productsWithSentinelKeys(self::PRODUCTS);

        $source = app(ImportSourceFactory::class)::from(EagerProduct::class);
        $index = Index::fromSource($source);
        $store->start(self::TOKEN, self::CHUNKS, $index->name());

        $this->chunkJob($source, $index, 0, true)->handle($this->elasticsearch);

        $context = $this->profileLogContext();
        $fetch = $context['slow_query']['fetch'];

        $this->assertSame(
            2,
            $context['queries']['fetch'],
            'The fetch ran the base select plus the eager load, so a placeholder run really was logged.'
        );
        $this->assertIsArray($fetch);

        // The marker names the count, so a 1000-key IN is still recognisable as
        // one — itself a finding — instead of being flattened to "an IN".
        $this->assertMatchesRegularExpression(
            '/in \(\?×(3|4)\)/u',
            $fetch['sql'],
            'A placeholder run must collapse to a marker naming how many placeholders it had.'
        );
        $this->assertSame(
            0,
            preg_match('/\?(?:\s*,\s*\?){2,}/', $fetch['sql']),
            'No uncollapsed run may survive into the output: that is what eats the truncation budget.'
        );

        // Collapsing shortens the statement without costing it its identity: the
        // verb and the table an operator needs for EXPLAIN are still there.
        $this->assertStringContainsString('select', $fetch['sql']);
        $this->assertStringContainsString('products', $fetch['sql']);
        $this->assertLessThanOrEqual(
            PullFromSource::SLOW_QUERY_SQL_CHARS + 3,
            mb_strlen($fetch['sql']),
            'Capture is bounded: the budget plus the ellipsis.'
        );

        // The eager load's `in (...)` was bound to the three sentinel custom_keys
        // of this chunk. Not one of them may appear anywhere in the captured
        // statement — the marker replaced the placeholders, it did not expand them.
        $this->assertStringNotContainsString(
            self::BINDING_SENTINEL,
            (string) json_encode($context['slow_query']),
            'A BOUND VALUE REACHED THE OUTPUT. Collapsing a placeholder run must never reveal what was bound to it.'
        );

        // Nothing lazy-loaded, because the relation is eager-loaded, so the index
        // phase issued no query at all — the null half of the contract.
        $this->assertSame(0, $context['queries']['index']);
        $this->assertNull($context['slow_query']['index']);

        $this->assertTrue($store->isDone(self::TOKEN, 0));
        $this->assertSame(3, $this->indexedCount('products'));
    }

    /**
     * @test
     */
    public function the_wait_terminal_prints_the_slowest_query_under_the_finding_that_carries_one(): void
    {
        // The other end of the pipe: a fetch_dominant finding published by a
        // worker has to reach the operator's terminal AS A QUERY, under the remedy
        // it belongs to. This is the line that turns "the read is slow" into a
        // statement and a missing index.
        //
        // Seeded by hand for the same reason as the inline-rendering test above:
        // the sync driver has finished every job before --wait polls, and timing
        // findings cannot be provoked deterministically on an unknown machine.
        $store = $this->probeableStore();
        $token = 'wait-slow-query-token';

        // Shaped exactly as capture publishes it: one line, `?` placeholders, and
        // the collapsed-run marker in place of a 1000-key eager load.
        $sql = 'select * from "users" inner join "accounts" on "accounts"."id" = "users"."account_id" '
            .'where "users"."id" in (?×1000) order by "users"."id" asc';

        $store->start($token, 4, 'products_20240101');
        $store->recordProfileFinding(
            $token,
            ProfileDiagnostics::CODE_FETCH_DOMINANT,
            258439.8,
            (string) json_encode([
                'fetch_ms' => 258439.8,
                'total_ms' => 258710.8,
                'pct' => 99.9,
                'slow_sql' => $sql,
                'slow_ms' => 258439.8,
            ]),
            900,
            20
        );
        // A second finding that carries NO query, so the extra line can be shown
        // to belong to the finding that has one rather than to every finding.
        $store->recordProfileFinding(
            $token,
            ProfileDiagnostics::CODE_N_PLUS_ONE,
            480.0,
            (string) json_encode(['relation' => 'App\\Order::user', 'loads' => 480, 'relations' => 2]),
            900,
            20
        );
        $store->scriptStatuses(ImportRunStore::STATUS_RUNNING, ImportRunStore::STATUS_SUCCEEDED);

        $buffer = new BufferedOutput();
        $command = $this->commandWithOptions(['--profile-samples' => 'all'], $buffer);

        $waitForRun = new ReflectionMethod(ImportCommand::class, 'waitForRun');
        $waitForRun->setAccessible(true);
        $exitCode = $waitForRun->invoke($command, 'App\Order', $token, 'redis', 'reindex');

        $this->assertSame(
            ImportCommand::SUCCESS,
            $exitCode,
            'Naming the query is a diagnostic like any other: it cannot change the exit code.'
        );

        $text = $buffer->fetch();

        // The duration, and the statement itself verbatim on the line so it can be
        // selected and pasted straight into EXPLAIN — marker included.
        $this->assertStringContainsString('Slowest query in that phase: 258439.8 ms', $text);
        $this->assertStringContainsString($sql, $text);
        $this->assertStringContainsString('in (?×1000)', $text);

        // Said out loud where the operator copying it can read it.
        $this->assertStringContainsString('no bound values are ever captured', $text);

        // Under the remedy of its own finding, not above it and not instead of it:
        // the existing sentences are unchanged and this is an extra line.
        $this->assertStringContainsString('Fetching dominates [App\Order]', $text);
        $this->assertLessThan(
            (int) strpos($text, 'Slowest query in that phase'),
            (int) strpos($text, 'Index the columns the keyset scan'),
            'The query belongs under the remedy it explains.'
        );

        // Exactly once: printed when the code first appears, and not repeated by
        // the closing roll-up — and not attached to the n+1 finding, which carries
        // no statement.
        $this->assertSame(
            1,
            substr_count($text, 'Slowest query in that phase'),
            'Only a finding that carries a statement gets the line, and only on its first appearance.'
        );
        $this->assertStringContainsString('N+1 while indexing [App\Order]', $text);

        // Rendered through the translations like every other finding line, so
        // neither a raw key nor an unreplaced placeholder may reach the terminal —
        // including the short spellings the shared sentence reads.
        $this->assertStringNotContainsString('scout::import.', $text);
        $this->assertStringNotContainsString(':sql', $text);
        $this->assertStringNotContainsString(':ms ', $text);
        $this->assertStringNotContainsString(':slow_sql', $text);
    }

    // ---- harness -----------------------------------------------------------

    /**
     * An ImportCommand with its input bound to the real option definition, so
     * private methods can be driven directly. waitForRun() reads
     * --profile-samples to decide whether findings are worth polling for, so an
     * unbound input is not enough here.
     *
     * @param  array<string, mixed>  $options
     */
    private function commandWithOptions(array $options, BufferedOutput $buffer): ImportCommand
    {
        $command = new ImportCommand();
        $command->setLaravel($this->app);

        $this->setPrivate($command, 'input', new ArrayInput($options, $command->getDefinition()));
        $this->setPrivate($command, 'output', new OutputStyle(new ArrayInput([]), $buffer));

        return $command;
    }

    /**
     * @param  mixed  $value
     */
    private function setPrivate(ImportCommand $command, string $property, $value): void
    {
        // Declared on Illuminate\Console\Command (via InteractsWithIO), which is
        // where the reflection has to look.
        $reflection = new ReflectionProperty(Command::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($command, $value);
    }

    /**
     * Swap the container's run store for one that can be told to misbehave, and
     * return it. Bound as an instance because PullChunkJob resolves the store
     * from the container inside handle().
     */
    private function probeableStore(bool $throwOnRecord = false): ProbeableImportRunStore
    {
        $store = new ProbeableImportRunStore($throwOnRecord);
        $this->app->instance(ImportRunStore::class, $store);

        return $store;
    }

    /**
     * The import source for the lazy-loading Product variant.
     */
    private function source(): ImportSource
    {
        return app(ImportSourceFactory::class)::from(LazyProduct::class);
    }

    /**
     * A chunk job for one planned chunk of $source, with profiling on or off.
     *
     * The lock owner is null so no import lease is involved: these tests are
     * about the diagnostic channel, not about locking.
     */
    private function chunkJob(ImportSource $source, Index $index, int $chunkId, bool $profile): PullChunkJob
    {
        $chunks = $source->chunked();

        $this->assertCount(self::CHUNKS, $chunks, 'The plan these tests reason about is 3 + 2 rows.');

        /** @var ImportSource $chunk */
        $chunk = $chunks->get($chunkId);

        return new PullChunkJob(
            $source,
            new PullFromSource($chunk, $profile),
            $index,
            self::TOKEN,
            $chunkId,
            null,
            900,
            null,
            null
        );
    }

    private function products(int $amount): void
    {
        // Model events off, or Scout indexes each row on create and the chunk
        // jobs would no longer be the only writers.
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        factory(Product::class, $amount)->create();

        Product::setEventDispatcher($dispatcher);
    }

    /**
     * The same rows {@see products} creates, except that custom_key — the column
     * the lazy-loaded relation joins on, and therefore the value bound into every
     * relation query — is a known sentinel instead of a random uuid.
     *
     * That is what makes the privacy assertions non-vacuous: the captured
     * statement is provably the one this value was bound to, so its absence from
     * the output is a real property and not an accident of random data.
     */
    private function productsWithSentinelKeys(int $amount): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        // Unique per row, so each model resolves exactly one cheap row on demand
        // and the eager load produces one placeholder per model rather than a
        // deduplicated single key.
        for ($i = 0; $i < $amount; $i++) {
            factory(Product::class)->create(['custom_key' => self::BINDING_SENTINEL.'-'.$i]);
        }

        Product::setEventDispatcher($dispatcher);
    }

    /**
     * The structured context of the `scout:import chunk profile` line — the whole
     * metrics array a profiled chunk publishes, exactly as a worker's log
     * receives it.
     *
     * @return array<string, mixed>
     */
    private function profileLogContext(): array
    {
        foreach ($this->logged as $line) {
            if ($line['message'] === 'scout:import chunk profile') {
                return $line['context'];
            }
        }

        $this->fail('A profiled chunk must log `scout:import chunk profile` with its metrics.');
    }

    private function indexedCount(string $index): int
    {
        $this->elasticsearch->indices()->refresh(['index' => $index]);

        $response = $this->elasticsearch->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);

        return (int) $response['hits']['total']['value'];
    }

    /**
     * Start collecting log lines. MessageLogged is the only seam that reports
     * the structured context rather than a formatted string.
     */
    private function recordLogs(): void
    {
        $this->logged = [];

        if ($this->listening) {
            return;
        }

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = [
                'message' => $event->message,
                'context' => $event->context,
            ];
        });

        $this->listening = true;
    }

    /**
     * The `classification` of every recorded log line, which is how PullChunkJob
     * labels what happened to a chunk.
     *
     * @return array<int, string>
     */
    private function loggedClassifications(): array
    {
        $classifications = [];

        foreach ($this->logged as $line) {
            $classification = $line['context']['classification'] ?? null;
            if (is_string($classification)) {
                $classifications[] = $classification;
            }
        }

        return $classifications;
    }
}

/**
 * A run store that behaves exactly like the in-memory fake, except that
 * recordProfileFinding() counts its calls and can be told to throw.
 *
 * Written by hand rather than mocked because the safety test needs a store that
 * is fully functional for coordination (claim / done / status) while being
 * broken for exactly one method: that combination is the whole scenario — Redis
 * is reachable enough to run the import, and the diagnostic write is the only
 * thing that fails.
 */
final class ProbeableImportRunStore implements ImportRunStore
{
    public int $recordCalls = 0;

    private FakeImportRunStore $inner;

    private bool $throwOnRecord;

    /** @var array<int, string> */
    private array $scriptedStatuses = [];

    public function __construct(bool $throwOnRecord = false)
    {
        $this->inner = new FakeImportRunStore();
        $this->throwOnRecord = $throwOnRecord;
    }

    /**
     * Script what snapshot() reports as the run status, one value per call, the
     * last one repeating forever.
     *
     * Nothing else can produce a run that is observed RUNNING and then terminal:
     * the fake has no background clock, and the sync queue driver has already
     * finished every job before --wait looks. Only the status is overridden — the
     * chunk counts and the index name still come from the real fake.
     */
    public function scriptStatuses(string ...$statuses): void
    {
        $this->scriptedStatuses = $statuses;
    }

    public function recordProfileFinding(string $token, string $code, float $weight, string $payload, int $ttlSeconds, int $cap): bool
    {
        $this->recordCalls++;

        if ($this->throwOnRecord) {
            // What a Redis EVAL error, a NOSCRIPT, or a cluster failover in the
            // middle of a profiled chunk looks like from here.
            throw new RuntimeException('redis refused the profile finding script');
        }

        return $this->inner->recordProfileFinding($token, $code, $weight, $payload, $ttlSeconds, $cap);
    }

    /**
     * @return array<string, array{count:int, weight:float, data:array<string,scalar>}>
     */
    public function profileFindings(string $token): array
    {
        return $this->inner->profileFindings($token);
    }

    /**
     * Test helper: the chunk ids recorded as done, so a test can count them.
     *
     * @return array<int, int>
     */
    public function doneChunks(string $token): array
    {
        return array_keys($this->inner->runs[$token]['done'] ?? []);
    }

    public function start(string $token, int $total, string $index): void
    {
        $this->inner->start($token, $total, $index);
    }

    public function markDone(string $token, int $chunkId): int
    {
        return $this->inner->markDone($token, $chunkId);
    }

    public function isDone(string $token, int $chunkId): bool
    {
        return $this->inner->isDone($token, $chunkId);
    }

    public function total(string $token): int
    {
        return $this->inner->total($token);
    }

    public function status(string $token): ?string
    {
        return $this->inner->status($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $token): array
    {
        $snapshot = $this->inner->snapshot($token);

        if ($this->scriptedStatuses !== []) {
            $snapshot['status'] = count($this->scriptedStatuses) > 1
                ? array_shift($this->scriptedStatuses)
                : $this->scriptedStatuses[0];
        }

        return $snapshot;
    }

    public function refreshTtls(string $token, int $ttl): void
    {
        $this->inner->refreshTtls($token, $ttl);
    }

    /**
     * @deprecated See ImportRunStore::failIfNotDone().
     */
    public function failIfNotDone(string $token, int $chunkId): bool
    {
        return $this->inner->failIfNotDone($token, $chunkId);
    }

    public function claimChunk(string $token, int $chunkId, string $owner, int $ttlSeconds): bool
    {
        return $this->inner->claimChunk($token, $chunkId, $owner, $ttlSeconds);
    }

    public function releaseChunk(string $token, int $chunkId, string $owner): void
    {
        $this->inner->releaseChunk($token, $chunkId, $owner);
    }

    public function chunkInFlight(string $token, int $chunkId): bool
    {
        return $this->inner->chunkInFlight($token, $chunkId);
    }

    public function recordFailure(string $token, int $chunkId): int
    {
        return $this->inner->recordFailure($token, $chunkId);
    }

    public function failureCount(string $token): int
    {
        return $this->inner->failureCount($token);
    }

    public function failRun(string $token): bool
    {
        return $this->inner->failRun($token);
    }

    public function bumpRedispatch(string $token, int $chunkId): int
    {
        return $this->inner->bumpRedispatch($token, $chunkId);
    }

    /**
     * @param  array<int, array{0:int, 1:mixed, 2:mixed}>  $bounds
     */
    public function pushBounds(string $token, array $bounds): void
    {
        $this->inner->pushBounds($token, $bounds);
    }

    /**
     * @return array<int, array{0:int, 1:mixed, 2:mixed}>
     */
    public function popBounds(string $token, int $count): array
    {
        return $this->inner->popBounds($token, $count);
    }

    public function pendingBounds(string $token): int
    {
        return $this->inner->pendingBounds($token);
    }

    public function claimFinalization(string $token): bool
    {
        return $this->inner->claimFinalization($token);
    }

    public function succeedIfFinalizing(string $token, string $lockOwner): bool
    {
        return $this->inner->succeedIfFinalizing($token, $lockOwner);
    }

    public function finalizeFailedIfFinalizing(string $token, string $lockOwner): bool
    {
        return $this->inner->finalizeFailedIfFinalizing($token, $lockOwner);
    }

    public function acquireFinalizeLock(string $token, string $owner, int $ttlSeconds): bool
    {
        return $this->inner->acquireFinalizeLock($token, $owner, $ttlSeconds);
    }

    public function renewFinalizeLock(string $token, string $owner, int $ttlSeconds): bool
    {
        return $this->inner->renewFinalizeLock($token, $owner, $ttlSeconds);
    }

    public function releaseFinalizeLock(string $token, string $owner): void
    {
        $this->inner->releaseFinalizeLock($token, $owner);
    }

    public function supportsAtomicCoordination(): bool
    {
        return $this->inner->supportsAtomicCoordination();
    }
}

/**
 * Test-only Product variant that shares the products table and lazy-loads a
 * relation while building its document — the N+1 profiling exists to find.
 *
 * Deliberately kept out of tests/laravel/app (as NonIncrementingProduct in
 * tests/Integration/Searchable/DefaultImportSourceTest.php is) so
 * SearchableListFactory never discovers it as a searchable of its own. It keeps
 * Product's searchableAs(), so it writes to the same `products` index the rest
 * of the suite already cleans up.
 *
 * The relation is a self-join on the unique per-row custom_key, so each model
 * resolves exactly one cheap row on demand: one lazy-loading violation per
 * model in the chunk, and therefore a hit count that equals the chunk's size.
 */
class LazyProduct extends Product
{
    protected $table = 'products';

    /**
     * @return HasMany<Product>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'custom_key', 'custom_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            // Not eager-loaded anywhere, so reading it here is the violation.
            // Exactly the shape of the real bug: an innocent-looking accessor
            // inside toSearchableArray() that costs one query per model.
            'variants' => $this->variants->count(),
        ];
    }
}

/**
 * The HEALTHY counterpart of LazyProduct: the same relation, eager-loaded the way
 * the n+1 remedy tells you to.
 *
 * It exists to make placeholder collapsing deterministic. An eager load is what
 * produces the `where "custom_key" in (?, ?, ?, …)` that motivates collapsing in
 * the first place, and its two fetch-phase statements are deliberately BOTH
 * collapsible:
 *
 *  - the base select carries a `not in (?, ?, ?, ?)` guard (see
 *    makeAllSearchableUsing below), and
 *  - the eager load carries `in (?, ?, ?)`, one placeholder per model in the
 *    chunk.
 *
 * So the test never has to guess which of the two the machine timed as slower —
 * either answer must come back with a collapsed marker. The guard is written to
 * exclude nothing: the titles are faker sentences, so no real row can match those
 * four sentinels, and the fetched set is identical to Product's.
 */
class EagerProduct extends Product
{
    protected $table = 'products';

    /**
     * @return HasMany<Product>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'custom_key', 'custom_key');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function makeAllSearchableUsing(Builder $query)
    {
        return $query
            ->with('variants')
            ->whereNotIn('title', ['no-such-title-1', 'no-such-title-2', 'no-such-title-3', 'no-such-title-4']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            // Already loaded by the eager load above, so this costs no query —
            // which is also why the index phase of this model reports no slowest
            // statement at all.
            'variants' => $this->variants->count(),
        ];
    }
}
