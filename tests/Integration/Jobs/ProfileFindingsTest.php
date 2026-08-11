<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
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
