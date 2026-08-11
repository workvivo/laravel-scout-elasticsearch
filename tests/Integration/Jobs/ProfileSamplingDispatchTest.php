<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Matchish\ScoutElasticSearch\Console\Commands\ImportCommand;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullChunks;
use Matchish\ScoutElasticSearch\Jobs\Import;
use Matchish\ScoutElasticSearch\Jobs\ImportReaperJob;
use Matchish\ScoutElasticSearch\Jobs\ImportStages;
use Matchish\ScoutElasticSearch\Jobs\PullChunkJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\IntegrationTestCase;
use Throwable;

/**
 * `--profile-samples`: profile a bounded number of chunks, spread across the
 * whole plan, instead of every chunk. At PERF scale (~100k chunks) profiling
 * everything means ~100k log lines plus a second full serialization pass per
 * chunk, which is not a diagnosis — it is a second outage.
 *
 * The stride arithmetic itself is pinned in tests/Unit/Jobs/ProfileSamplingTest.php.
 * What is asserted here is that every dispatch path arrives at the *same*
 * per-chunk answer, because four of them compute it independently and none can
 * see the others: the planning hop, each continuation hop of a paged fan-out,
 * the reaper reviving a stranded chunk, and chunked() on the sequential path. A
 * drift between them would not fail anything loudly — it would just quietly
 * profile the wrong chunks, or profile them twice.
 *
 * Every plan below is 18 products at chunk size 3 (see TestCase) — 6 chunks —
 * with a target of 2 samples, so the stride is intdiv(6, 2) = 3 and the sampled
 * chunk ids are exactly {0, 3}. Chunk ids and their stages are private
 * internals, so they are read with reflection (as PagedFanOutTest already does):
 * a fan-out test has to see which chunk got which decision, and nothing public
 * exposes that.
 */
final class ProfileSamplingDispatchTest extends IntegrationTestCase
{
    /** 18 rows at chunk size 3. */
    private const CHUNKS = 6;

    /** intdiv(6, 2) = 3, so chunk 0 and chunk 3. */
    private const SAMPLES = 2;

    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    /**
     * The MessageLogged listener is registered at most once per test: registering
     * it again on a second recordLogs() would record every later line twice.
     */
    private bool $listening = false;

    private function withoutModelEvents(string $class, callable $callback): void
    {
        $dispatcher = $class::getEventDispatcher();
        $class::unsetEventDispatcher();
        $callback();
        $class::setEventDispatcher($dispatcher);
    }

    private function source(): ImportSource
    {
        return app(ImportSourceFactory::class)::from(Product::class);
    }

    /**
     * 18 products => 6 chunks of 3.
     */
    private function sixChunksOfProducts(): void
    {
        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 18)->create();
        });
    }

    /**
     * @test
     */
    public function a_sample_target_below_the_chunk_count_profiles_only_the_sampled_chunks(): void
    {
        Bus::fake();
        // Comfortably above the plan: this is the original single-hop fan-out.
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 1000);

        $this->sixChunksOfProducts();

        $this->planningHop('single-token', false, self::SAMPLES)->handle();

        Bus::assertNotDispatched(DispatchPullChunks::class);
        Bus::assertDispatched(PullChunkJob::class, self::CHUNKS);

        // Note the `profile` bool the hop carries is deliberately false: a sample
        // target switches profiling on by itself, which is exactly why the command
        // can derive that bool from the target instead of taking a second flag.
        $this->assertSame(
            [0 => true, 1 => false, 2 => false, 3 => true, 4 => false, 5 => false],
            $this->dispatchedProfileFlags(),
            'exactly the chunks on the stride carry a profiling stage; every other chunk must be untouched'
        );
    }

    /**
     * @test
     */
    public function the_paged_fan_out_samples_exactly_the_same_chunks_as_a_single_hop(): void
    {
        // THE CRITICAL CASE. A continuation hop does not re-plan, so it has no
        // chunk count of its own and has to recompute the stride from the total
        // carried in its payload. If it got that wrong — recomputing from the page
        // size, or from a fresh plan, or resetting per hop — the sampled set would
        // silently drift between pages and the samples would stop being spread
        // evenly across the plan. Nothing else in the system would notice.
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 1000);
        $this->sixChunksOfProducts();

        $this->planningHop('single-token', false, self::SAMPLES)->handle();
        $singleHop = $this->dispatchedProfileFlags();

        // Same plan, same sample target, now fanned out over 3 hops of 2 chunks.
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);

        $hops = $this->runHops($this->planningHop('paged-token', false, self::SAMPLES));

        $this->assertSame(3, $hops, 'the plan must actually have been paged, or this test proves nothing');
        Bus::assertDispatched(PullChunkJob::class, self::CHUNKS);

        $paged = $this->dispatchedProfileFlags();

        $this->assertSame(
            $singleHop,
            $paged,
            'the paged fan-out must sample the same chunk ids as a single hop: the sampling may not drift or reset between pages'
        );
        $this->assertSame([0 => true, 1 => false, 2 => false, 3 => true, 4 => false, 5 => false], $paged);

        // Chunk 3 is deliberately in the *second* page (page 1 is chunks 0-1), so
        // this only passes if a continuation hop reproduced the stride.
        $store = app(ImportRunStore::class);
        $this->assertSame(0, $store->pendingBounds('paged-token'));
        $this->assertSame(self::CHUNKS, $store->total('paged-token'));
    }

    /**
     * @test
     */
    public function profile_without_a_sample_count_still_profiles_every_dispatched_chunk(): void
    {
        // An INTERNAL contract, on both fan-out shapes. No command line produces
        // this pair any more — the command derives the bool from the target, so a
        // profiled run always carries both — but the payloads still transport them
        // separately (a continuation hop, and the reaper, are handed whatever the
        // planning hop was constructed with), so "bool set, no target" has to keep
        // meaning every chunk rather than silently meaning nothing.
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 1000);
        $this->sixChunksOfProducts();

        $this->planningHop('single-token', true, null)->handle();

        $this->assertSame(array_fill(0, self::CHUNKS, true), $this->dispatchedProfileFlags());

        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);

        $this->runHops($this->planningHop('paged-token', true, null));

        $this->assertSame(array_fill(0, self::CHUNKS, true), $this->dispatchedProfileFlags());
    }

    /**
     * @test
     */
    public function without_either_option_no_chunk_carries_profiling(): void
    {
        // Neither half of the payload's profiling pair set — which is what a plain
        // `scout:import --parallel` with no --profile-samples resolves to.
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);
        $this->sixChunksOfProducts();

        $this->runHops($this->planningHop('paged-token', false, null));

        $this->assertSame(array_fill(0, self::CHUNKS, false), $this->dispatchedProfileFlags());
    }

    /**
     * @test
     */
    public function a_sample_target_larger_than_the_plan_profiles_every_chunk(): void
    {
        // The operator cannot know the chunk count in advance (it comes from the
        // MIN/MAX key span, not the row count), so over-asking has to degrade to
        // "all" rather than to nothing.
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);
        $this->sixChunksOfProducts();

        $this->runHops($this->planningHop('paged-token', false, 100));

        $this->assertSame(array_fill(0, self::CHUNKS, true), $this->dispatchedProfileFlags());

        // --profile-samples=all is this same case taken to its limit: the command
        // resolves "all" to the PHP_INT_MAX sentinel, which needs no branch of its
        // own anywhere — it is just a target no plan can exceed, so it degrades to
        // a stride of 1 through the identical arithmetic, on every hop.
        Bus::fake();

        $this->runHops($this->planningHop('sentinel-token', false, ImportCommand::PROFILE_SAMPLES_ALL));

        $this->assertSame(array_fill(0, self::CHUNKS, true), $this->dispatchedProfileFlags());
    }

    /**
     * @test
     */
    public function the_fan_out_log_lines_report_the_sampling_in_effect(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 1000);
        $this->sixChunksOfProducts();

        $this->recordLogs();
        $this->planningHop('single-token', false, self::SAMPLES)->handle();

        $planning = $this->logContexts('scout:import dispatching parallel chunks');
        $this->assertCount(1, $planning);
        // An operator reading this line must never mistake a sampled run for full
        // coverage, so the stride is spelled out rather than implied.
        $this->assertSame('1 in 3', $planning[0]['profile']);
        $this->assertSame(self::SAMPLES, $planning[0]['profile_samples']);
        $this->assertSame(self::CHUNKS, $planning[0]['chunks']);

        // Every page of a paged fan-out repeats it: a reader landing on one page
        // gets the same warning as one reading the planning line.
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);
        $this->recordLogs();
        $this->runHops($this->planningHop('paged-token', false, self::SAMPLES));

        $pages = $this->logContexts('scout:import dispatched chunk page');
        $this->assertCount(3, $pages);
        foreach ($pages as $page) {
            $this->assertSame('1 in 3', $page['profile']);
            $this->assertSame(self::SAMPLES, $page['profile_samples']);
        }
    }

    /**
     * @test
     */
    public function the_log_lines_distinguish_full_coverage_from_no_profiling(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 1000);
        $this->sixChunksOfProducts();

        $this->recordLogs();
        $this->planningHop('all-token', true, null)->handle();
        $planning = $this->logContexts('scout:import dispatching parallel chunks');
        $this->assertSame('all', $planning[0]['profile']);
        $this->assertNull($planning[0]['profile_samples']);

        $this->recordLogs();
        $this->planningHop('off-token', false, null)->handle();
        $planning = $this->logContexts('scout:import dispatching parallel chunks');
        $this->assertFalse($planning[0]['profile'], 'profiling off must read as false, not as an empty string');
        $this->assertNull($planning[0]['profile_samples']);

        // What --profile-samples=all looks like in the log: the sentinel is a
        // sample target like any other, so the operator-facing label has to be
        // derived from the resulting stride ('all') and not from the raw number —
        // a log line reading "1 in 9223372036854775807" would be a lie.
        $this->recordLogs();
        $this->planningHop('sentinel-token', true, ImportCommand::PROFILE_SAMPLES_ALL)->handle();
        $planning = $this->logContexts('scout:import dispatching parallel chunks');
        $this->assertSame('all', $planning[0]['profile']);
        $this->assertSame(ImportCommand::PROFILE_SAMPLES_ALL, $planning[0]['profile_samples']);
    }

    /**
     * @test
     */
    public function the_sequential_path_honours_the_sample_count_too(): void
    {
        // A huge sequential import has the identical problem — same chunk count,
        // same flood of log lines — so the sampling cannot be a --parallel-only
        // feature.
        $this->sixChunksOfProducts();

        $stages = ImportStages::fromSource($this->source(), false, null, self::SAMPLES);

        $this->assertSame(
            [true, false, false, true, false, false],
            $this->pullStageFlags($stages->all()),
            'the sequential plan must sample the same positions the fan-out does'
        );

        // ...and the sequential *job* has to forward it, not just accept it.
        $import = new Import($this->source(), null, 3600, false, self::SAMPLES);
        $buildStages = new ReflectionMethod($import, 'stages');
        $buildStages->setAccessible(true);
        /** @var \Illuminate\Support\Collection<int, object> $jobStages */
        $jobStages = $buildStages->invoke($import);

        $this->assertSame([true, false, false, true, false, false], $this->pullStageFlags($jobStages->all()));
    }

    /**
     * @test
     */
    public function the_sequential_path_profiles_every_chunk_without_a_sample_count(): void
    {
        $this->sixChunksOfProducts();

        $this->assertSame(
            array_fill(0, self::CHUNKS, true),
            $this->pullStageFlags(ImportStages::fromSource($this->source(), true)->all()),
            'the profile bool without a target must keep meaning every chunk on the sequential path as well'
        );
        $this->assertSame(
            array_fill(0, self::CHUNKS, false),
            $this->pullStageFlags(ImportStages::fromSource($this->source())->all()),
            'the default call — the one every existing caller makes — must profile nothing'
        );
    }

    /**
     * @test
     */
    public function a_reaped_chunk_keeps_the_profile_decision_it_was_dispatched_with(): void
    {
        // The reaper re-plans from scratch, so it is the one place where a chunk
        // could plausibly start or stop profiling halfway through a run. It
        // recomputes the stride from the run record's total instead.
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $this->sixChunksOfProducts();

        $source = $this->source();
        // Index::fromSource() mints a fresh random name per call, so the reaper
        // has to be handed the same instance the run record was published with.
        $index = Index::fromSource($source);
        $token = 'reaped-token';

        $store = app(ImportRunStore::class);
        $store->start($token, self::CHUNKS, $index->name());

        // Chunks 1 and 3 are stranded: one off the stride, one on it.
        foreach ([0, 2, 4, 5] as $chunkId) {
            $store->markDone($token, $chunkId);
        }

        (new ImportReaperJob($source, $index, $token, null, 900, null, null, false, 0, self::SAMPLES))->handle();

        Bus::assertDispatched(PullChunkJob::class, 2);
        $this->assertSame(
            [1 => false, 3 => true],
            $this->dispatchedProfileFlags(),
            'a reaped chunk must neither start nor stop profiling just because the reaper dispatched it'
        );
    }

    /**
     * The whole command surface in one table: what each value of the single
     * profiling option means for how many chunks actually log a profile line, run
     * end-to-end on the sync connection so the chunk jobs really execute.
     *
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: bool}>
     */
    public function commandProfileOptions(): array
    {
        return [
            // options, chunks that profile (of 6), warns about the option
            'a sample target implies profiling' => [['--profile-samples' => '2'], 2, false],
            // "all" is the only way to ask for every chunk now that the bare flag
            // is gone, and it has to reach the workers as a stride of 1 — the
            // PHP_INT_MAX sentinel travelling the ordinary sample-target path, all
            // the way through to a real profile line per chunk.
            'all profiles every chunk' => [['--profile-samples' => 'all'], self::CHUNKS, false],
            // Typed by hand on a terminal, so shouting it or fat-fingering a space
            // must not silently turn profiling off. (Each spelling is checked more
            // cheaply in the_command_resolves_all_to_the_largest_possible_sample_target;
            // one of them earns a full end-to-end run.)
            'all tolerates case and surrounding whitespace' => [['--profile-samples' => ' All '], self::CHUNKS, false],
            'no target profiles nothing' => [[], 0, false],
            // A bad diagnostic knob must never block an import. It cannot fall back
            // to profiling either: the target is now the only thing that asks for
            // profiling, so an unusable one leaves the run unprofiled — loudly.
            'zero stays off' => [['--profile-samples' => '0'], 0, true],
            'non-numeric stays off' => [['--profile-samples' => 'abc'], 0, true],
            'negative stays off' => [['--profile-samples' => '-3'], 0, true],
        ];
    }

    /**
     * @test
     * @dataProvider commandProfileOptions
     *
     * @param  array<string, mixed>  $options
     */
    public function the_command_threads_the_profile_decision_through_to_the_chunks(array $options, int $expectedProfiled, bool $expectsWarning): void
    {
        // Sync executes queued jobs inline, so the whole chain — prepare, fan-out,
        // every chunk job — runs in this process and the per-chunk profile log
        // lines are the ground truth for what the command decided. --force is
        // required precisely because the connection is sync.
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->sixChunksOfProducts();

        $this->recordLogs();
        $output = new BufferedOutput();

        $exitCode = Artisan::call('scout:import', array_merge([
            'searchable' => [Product::class],
            '--parallel' => true,
            '--force' => true,
        ], $options), $output);

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertCount(
            $expectedProfiled,
            $this->logContexts('scout:import chunk profile'),
            'the number of chunks that logged a profile breakdown must match the sampling asked for'
        );

        // Whatever the diagnostics did, the import itself is untouched.
        $this->assertSame(18, $this->searchTotal((new Product())->searchableAs()));

        $text = $output->fetch();
        if ($expectsWarning) {
            $this->assertStringContainsString('--profile-samples', $text);
            $this->assertStringContainsString('positive integer', $text);
        } else {
            $this->assertStringNotContainsString('--profile-samples option must', $text);
        }
    }

    /**
     * @test
     * @dataProvider invalidSampleCounts
     */
    public function an_invalid_sample_count_warns_and_still_dispatches_the_import(string $samples): void
    {
        // Contrast with invalid_chunk_option_fails_fast(): --chunk aborts because
        // it changes what gets indexed, --profile-samples only changes what gets
        // logged, so it warns and carries on. The dispatched job is the proof.
        Bus::fake();

        $output = new BufferedOutput();
        $exitCode = Artisan::call('scout:import', [
            'searchable' => [Product::class],
            '--profile-samples' => $samples,
        ], $output);

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('positive integer', $output->fetch());

        Bus::assertDispatched(Import::class, 1);
        $import = Bus::dispatched(Import::class)->first();
        $this->assertNull(
            $this->peek($import, 'profileSamples'),
            'an unusable sample count must reach the job as null, i.e. as "not provided"'
        );
        $this->assertFalse(
            $this->peek($import, 'profile'),
            'the bool is derived from the target, so an unusable target leaves the run unprofiled rather than profiling everything'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function invalidSampleCounts(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-3'],
            'non-numeric' => ['abc'],
            'empty' => [''],
        ];
    }

    /**
     * @test
     */
    public function the_command_passes_a_usable_sample_target_to_the_sequential_job(): void
    {
        Bus::fake();

        $output = new BufferedOutput();
        $exitCode = Artisan::call('scout:import', [
            'searchable' => [Product::class],
            '--profile-samples' => '2',
        ], $output);

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('--profile-samples option must', $output->fetch());

        $import = Bus::dispatched(Import::class)->first();
        $this->assertSame(2, $this->peek($import, 'profileSamples'));
        // The two values the job carries cannot disagree: profiling is on for a run
        // precisely when a usable target exists, so the command resolves the option
        // once and hands down both halves of that one answer.
        $this->assertTrue($this->peek($import, 'profile'));
    }

    /**
     * @test
     * @dataProvider allSpellings
     */
    public function the_command_resolves_all_to_the_largest_possible_sample_target(string $spelling): void
    {
        // The end of the wire for --profile-samples=all: what the job is actually
        // handed. Not a mode, not a flag — the biggest target there is, which the
        // existing stride arithmetic (max(1, intdiv(total, samples))) turns into
        // every chunk for any plan that can exist.
        Bus::fake();

        $output = new BufferedOutput();
        $exitCode = Artisan::call('scout:import', [
            'searchable' => [Product::class],
            '--profile-samples' => $spelling,
        ], $output);

        $this->assertSame(ImportCommand::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('--profile-samples option must', $output->fetch());

        $import = Bus::dispatched(Import::class)->first();
        $this->assertSame(PHP_INT_MAX, ImportCommand::PROFILE_SAMPLES_ALL);
        $this->assertSame(ImportCommand::PROFILE_SAMPLES_ALL, $this->peek($import, 'profileSamples'));
        $this->assertTrue($this->peek($import, 'profile'));

        $this->assertSame(
            1,
            PullFromSource::profileStride(self::CHUNKS, true, ImportCommand::PROFILE_SAMPLES_ALL),
            'the sentinel must arrive at a stride of 1 through the ordinary sample-target math, with no special case'
        );
    }

    /**
     * Every spelling of "all" an operator might type.
     *
     * @return array<string, array{0: string}>
     */
    public function allSpellings(): array
    {
        return [
            'lower case' => ['all'],
            'upper case' => ['ALL'],
            'mixed case' => ['AlL'],
            'padded' => ['  all  '],
        ];
    }

    /**
     * @test
     */
    public function the_bare_profile_flag_is_gone_and_is_rejected_rather_than_ignored(): void
    {
        // --profile-samples is now the single profiling flag, and the bare --profile
        // it replaced must be *rejected*, not quietly swallowed. An operator with it
        // in a runbook has to be told, because the silent alternative is the worst
        // outcome available: a run they believe is profiled that logs nothing.
        Bus::fake();

        $definition = (new ImportCommand())->getDefinition();
        $this->assertFalse($definition->hasOption('profile'), 'the bare --profile flag must no longer be defined');
        $this->assertTrue($definition->hasOption('profile-samples'));

        $thrown = null;

        try {
            Artisan::call('scout:import', [
                'searchable' => [Product::class],
                '--profile' => true,
            ], new BufferedOutput());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        // Symfony refuses the run while binding the input, before the command body
        // is ever entered — so nothing was imported and nothing was dispatched.
        $this->assertInstanceOf(
            InvalidOptionException::class,
            $thrown,
            'passing --profile must fail loudly; an unknown option accepted in silence would be the real defect'
        );
        $this->assertStringContainsString('"--profile" option does not exist', $thrown->getMessage());

        Bus::assertNothingDispatched();
    }

    /**
     * A planning hop (cursor 0) for the products plan.
     */
    private function planningHop(string $token, bool $profile, ?int $samples): DispatchPullChunks
    {
        $source = $this->source();

        return new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            null,
            null,
            'owner-token',
            900,
            $token,
            $profile,
            0,
            null,
            $samples
        );
    }

    /**
     * Run the planning hop and then every hop it re-enqueues, exactly as a worker
     * would, and return how many hops ran. Each hop queues at most one successor,
     * so the n-th queued DispatchPullChunks is hop n.
     */
    private function runHops(DispatchPullChunks $planning, int $limit = 20): int
    {
        $planning->handle();
        $hops = 1;

        while (($next = Bus::dispatched(DispatchPullChunks::class)->get($hops - 1)) !== null) {
            $this->assertLessThan($limit, $hops, 'the paged fan-out must terminate');
            $next->handle();
            $hops++;
        }

        return $hops;
    }

    /**
     * Whether each dispatched chunk job carries a profiling stage, keyed by chunk
     * id in ascending order. Fails the test if a chunk id was dispatched twice.
     *
     * The chunk id and the stage's profile flag are both private internals, so
     * they are read with reflection: nothing public reports which chunk got which
     * decision, and that mapping is the entire subject of this file.
     *
     * @return array<int, bool>
     */
    private function dispatchedProfileFlags(): array
    {
        $flags = [];

        foreach (Bus::dispatched(PullChunkJob::class) as $job) {
            $chunkId = $this->peek($job, 'chunkId');
            $this->assertArrayNotHasKey($chunkId, $flags, "chunk {$chunkId} was dispatched more than once");

            $flags[$chunkId] = $this->peek($this->peek($job, 'stage'), 'profile');
        }

        ksort($flags);

        return $flags;
    }

    /**
     * The profile flag of every PullFromSource in a sequential stage list, in
     * plan order (the surrounding clean-up/create/refresh/switch stages are not
     * chunk stages and carry no decision).
     *
     * @param  array<int, object>  $stages
     * @return array<int, bool>
     */
    private function pullStageFlags(array $stages): array
    {
        $flags = [];

        foreach ($stages as $stage) {
            if ($stage instanceof PullFromSource) {
                $flags[] = $this->peek($stage, 'profile');
            }
        }

        return $flags;
    }

    /**
     * Start collecting log lines. The fan-out and the per-chunk breakdown both
     * go through logger(), and MessageLogged is the only seam that reports the
     * structured context rather than a formatted string.
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
     * The context array of every recorded log line with this exact message.
     *
     * @return array<int, array<string, mixed>>
     */
    private function logContexts(string $message): array
    {
        $contexts = [];

        foreach ($this->logged as $line) {
            if ($line['message'] === $message) {
                $contexts[] = $line['context'];
            }
        }

        return $contexts;
    }

    private function searchTotal(string $index): int
    {
        $response = $this->elasticsearch->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);

        return $response['hits']['total']['value'];
    }

    /**
     * @return mixed
     */
    private function peek(object $object, string $property)
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}
