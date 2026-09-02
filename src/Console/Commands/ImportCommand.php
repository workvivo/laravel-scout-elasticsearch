<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportProbe;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\QueueTimingPreflight;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullChunks;
use Matchish\ScoutElasticSearch\Jobs\Import;
use Matchish\ScoutElasticSearch\Jobs\QueueableJob;
use Matchish\ScoutElasticSearch\Jobs\RollbackImportJob;
use Matchish\ScoutElasticSearch\Jobs\StageJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanUp;
use Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use Matchish\ScoutElasticSearch\Searchable\SearchableListFactory;
use OpenSearch\Client;

/**
 * @phpstan-import-type Report from \Matchish\ScoutElasticSearch\Import\QueueTimingPreflight
 * @phpstan-import-type ProbeReport from \Matchish\ScoutElasticSearch\Import\ImportProbe
 */
final class ImportCommand extends Command
{
    const DEFAULT_LOCK_TTL = 3600;

    /**
     * Seconds --wait will poll for the run record to be created (prepare stages +
     * DispatchPullChunks) before giving up and leaving the work queued.
     */
    const DEFAULT_WAIT_TIMEOUT = 120;

    /**
     * Cap on distinct profiling findings assumed when the config key is absent,
     * kept in step with config/elasticsearch.php and with PullChunkJob. Only used
     * to decide whether findings can exist at all: 0 there means the workers
     * publish nothing, so --wait has nothing to poll for.
     */
    const DEFAULT_PROFILE_FINDINGS = 20;

    /**
     * The sample target `--profile-samples=all` resolves to.
     *
     * Profiling every chunk is not a separate mode, it is the degenerate case of
     * sampling: PullFromSource::profileStride() turns a target into a stride with
     * max(1, intdiv($total, $samples)), so any target at or above the chunk count
     * already collapses to a stride of 1, i.e. every chunk. Asking for
     * PHP_INT_MAX samples is therefore "profile every chunk" expressed in the one
     * vocabulary the jobs already speak — no extra flag, no branch in the stride
     * math, and the same value on every continuation hop and in the reaper.
     */
    const PROFILE_SAMPLES_ALL = PHP_INT_MAX;

    /**
     * Chunks --probe measures when --probe-samples is absent or unusable. Three
     * is enough to see a spread (and so to judge whether the mean means
     * anything) while still finishing in seconds on a table with millions of
     * rows, which is the entire point of the flag.
     */
    const DEFAULT_PROBE_SAMPLES = 3;

    /**
     * Workers the --probe estimate is divided across when --probe-workers is
     * absent or unusable. One, i.e. the serial estimate, because that is the
     * only figure the probe can defend without being told how the fan-out will
     * be staffed.
     */
    const DEFAULT_PROBE_WORKERS = 1;

    /**
     * Every placeholder the profile-finding messages may name.
     *
     * A finding's numbers are read back out of the run record, where they were
     * left as free-form JSON by a worker process — possibly running a different
     * release of this package. Seeding all of them with "?" means a key that never
     * arrived degrades to a "?" in the sentence instead of leaving a literal
     * ":loads" in front of the operator.
     */
    const PROFILE_FINDING_PLACEHOLDERS = [
        'relation', 'loads', 'relations',
        'total_ms', 'timeout', 'pct',
        'queries', 'fetched',
        'fetch_ms', 'index_ms', 'bulk_ms',
        'avg_kb', 'payload_kb', 'indexed',
        // The slowest query of the dominant phase, which ProfileDiagnostics adds
        // to fetch_dominant and index_dominant only. Registered under BOTH
        // spellings: `slow_sql`/`slow_ms` as the worker publishes them into the
        // run record (a cross-version wire contract, not renamed here), and the
        // short `sql`/`ms` the profile_finding_slow_query sentence reads. Either
        // one absent therefore prints "?" rather than a literal ":sql".
        'slow_sql', 'slow_ms', 'sql', 'ms',
    ];

    /**
     * The phases a chunk is measured in, in the order they run — the sub-keys of
     * the `slow_query` map PullFromSource publishes, and the order the --probe
     * slow-query block prints them in.
     *
     * @var list<string>
     */
    const PROFILED_PHASES = ['fetch', 'filter', 'index'];

    /**
     * @inheritdoc
     */
    protected $signature = 'scout:import {searchable?* : The name of the searchable}
        {--parallel : Import chunks in parallel across queue workers}
        {--queue= : Queue the import jobs run on (defaults to scout.queue, then the app default queue)}
        {--connection= : Queue connection the import jobs run on (defaults to scout.queue, then the app default queue)}
        {--force : Run --parallel even when the resolved queue connection is synchronous}
        {--wait : With --parallel, block and show chunk progress until the import finishes}
        {--preflight : With --parallel, report the queue timing pre-flight and exit without importing anything}
        {--chunk= : Rows per chunk for this run (overrides the scout.chunk.searchable config)}
        {--fast-plan : Plan chunk boundaries from bare keys, skipping the eager-load join/filters (faster on joined models)}
        {--profile-samples= : Profile roughly this many chunks, spread across the plan, or "all" for every chunk}
        {--probe : Measure a small sample of chunks against a throwaway index, report the findings, and exit without importing}
        {--probe-samples= : How many chunks --probe measures, spread across the plan (default 3)}
        {--probe-workers= : Divide the --probe time estimate across this many workers (default 1)}';
    /**
     * @inheritdoc
     */
    protected $description = 'Create new index and import all searchable into the one';

    /**
     * @inheritdoc
     */
    public function handle(): int
    {
        // --probe measures a few chunks in-process and exits: it dispatches
        // nothing, so nothing about the queue can be relevant to it. Every
        // --parallel gate below (Redis coordination, an atomic lock store, an
        // asynchronous connection, the queue timing pre-flight) asks whether
        // this host could run a fan-out — and none of those answers may stand
        // between an operator and a diagnosis that never touches a queue. So
        // while probing, --parallel is simply ignored rather than gated: the
        // probe is useful for a sequential import too, which is also why it does
        // not require --parallel and does not complain about its absence.
        $probing = (bool) $this->option('probe');

        // --parallel fans chunks out onto a queue, so it only does real work on
        // an asynchronous connection. It does NOT require scout.queue (that flag
        // only governs per-model index syncs): the connection is resolved from
        // --connection, then scout.queue, then the app's default queue. Bail
        // early if that resolves to the sync driver, unless --force is given.
        if ($this->option('parallel') && ! $probing) {
            if (! app(ImportRunStore::class)->supportsAtomicCoordination()) {
                $this->error(trans('scout::import.parallel_requires_redis'));

                return self::FAILURE;
            }

            if (config('cache.default') === 'file') {
                $this->error(trans('scout::import.parallel_requires_atomic_lock_store'));

                return self::FAILURE;
            }

            // Queue timing: a chunk has to survive its own re-delivery window,
            // or the broker hands a second copy to another worker while the
            // first is still working — on SQS that copy arrives already looking
            // exhausted and is failed before the job body runs. Only proven
            // (probed) misordering aborts; everything else warns. --preflight
            // stops here either way, having planned and dispatched nothing.
            $preflight = $this->queueTimingPreflight();
            if ($preflight !== null) {
                return $preflight;
            }
        }

        if ($this->option('parallel') && ! $probing && ! $this->option('force')) {
            $connection = $this->resolvedConnection();

            if ($this->isSyncConnection($connection)) {
                $this->error(trans('scout::import.parallel_requires_async_queue', [
                    'connection' => $connection ?? 'sync',
                ]));

                return self::FAILURE;
            }
        }

        if ($this->option('chunk') !== null && (int) $this->option('chunk') < 1) {
            $this->error(trans('scout::import.invalid_chunk'));

            return self::FAILURE;
        }

        // Unlike --chunk this warns and carries on: --profile-samples only picks
        // which chunks get a diagnostic log line, and a mistyped diagnostic knob
        // must never be able to block an import. An unusable value reads as "no
        // sample target", and since the target is now the only way to ask for
        // profiling, that means this run is simply not profiled.
        if ($this->option('profile-samples') !== null && $this->profileSamplesOption() === null) {
            $this->warn(trans('scout::import.invalid_profile_samples'));
        }

        // Same rule for both --probe knobs, and for the same reason: a mistyped
        // number on a DIAGNOSTIC must never abort. Each falls back to its
        // default and says so, because a probe that silently measured a
        // different number of chunks than was asked for is worse than one that
        // measured the default and admitted it.
        if ($this->option('probe-samples') !== null && $this->probeSamplesOption() === null) {
            $this->warn(trans('scout::import.invalid_probe_samples', ['default' => self::DEFAULT_PROBE_SAMPLES]));
        }

        if ($this->option('probe-workers') !== null && $this->probeWorkersOption() === null) {
            $this->warn(trans('scout::import.invalid_probe_workers', ['default' => self::DEFAULT_PROBE_WORKERS]));
        }

        if ($this->option('wait') && ! $this->option('parallel')) {
            $this->warn(trans('scout::import.wait_needs_parallel'));
        }

        if ($this->option('preflight') && ! $this->option('parallel')) {
            $this->warn(trans('scout::import.preflight_needs_parallel'));
        }

        $failed = false;

        $this->searchableList((array) $this->argument('searchable'))
        ->each(function ($searchable) use (&$failed) {
            if ($this->import($searchable) === self::FAILURE) {
                $failed = true;
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The queue connection --parallel work is dispatched on: the explicit
     * --connection option, else the scout.queue connection when configured,
     * else the application's default queue connection.
     */
    private function resolvedConnection(): ?string
    {
        return $this->stringOption('connection')
            ?: $this->stringConfig('scout.queue.connection')
            ?: $this->stringConfig('queue.default');
    }

    /**
     * The queue name --parallel work is dispatched on: the explicit --queue
     * option, else the scout.queue queue when configured (null = the
     * connection's default queue).
     */
    private function resolvedQueue(): ?string
    {
        return $this->stringOption('queue') ?: $this->stringConfig('scout.queue.queue');
    }

    private function isSyncConnection(?string $connection): bool
    {
        if ($connection === null) {
            return true;
        }

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    /**
     * Report the queue timing pre-flight for a --parallel run.
     *
     * Returns an exit code when the command must stop here — a proven fatal
     * misordering, or a --preflight dry run, which always stops before a lock is
     * taken or a job is dispatched — and null when the import should carry on.
     */
    private function queueTimingPreflight(): ?int
    {
        $dryRun = (bool) $this->option('preflight');
        $force = (bool) $this->option('force');

        // --force already waives the sync-connection abort, so it waives this
        // check too — but never silently: a forced run says out loud that the
        // re-delivery ordering went unverified. --preflight is a request for the
        // report itself, so it still runs and only downgrades its fatals.
        if ($force && ! $dryRun) {
            $this->warn(trans('scout::import.preflight_skipped_forced'));

            return null;
        }

        // Master switch off: stay silent on a real run. A --preflight dry run
        // still prints the settings table, saying the check itself is disabled,
        // because being asked for the report is not the same as being checked.
        if (! QueueTimingPreflight::isEnabled() && ! $dryRun) {
            return null;
        }

        $connection = $this->resolvedConnection();
        $queue = $this->resolvedQueue();

        // Resolved from the container (its probe seam defaults to the real
        // read-only GetQueueAttributes call) so a test can bind a fake probe and
        // never touch AWS.
        $report = app(QueueTimingPreflight::class)->inspect($connection, $queue, $this->chunkOption());

        $forced = false;
        if ($force && QueueTimingPreflight::hasFatal($report)) {
            $report = QueueTimingPreflight::withoutFatals($report);
            $forced = true;
        }

        // The 21-row fact table is REFERENCE material: it exists so an operator
        // auditing their queue timing can see every number and whether it was
        // probed, declared or merely assumed. That is what --preflight is for, so
        // that is the only place it prints. A real import prints the findings and
        // nothing else — they are the actionable part, and burying two warnings
        // under a full-screen table before every single run trains people to
        // scroll past both. --preflight is one keystroke away when the numbers
        // behind a warning are wanted.
        if ($dryRun) {
            $this->renderPreflightReport($report);
        }

        $this->renderPreflightFindings($report);

        if ($forced) {
            $this->warn(trans('scout::import.preflight_forced'));
        }

        if ($report['fatal']) {
            $this->error(trans('scout::import.preflight_abort'));

            return self::FAILURE;
        }

        if ($dryRun && $report['enabled'] && $report['findings'] === []) {
            $this->line(trans('scout::import.preflight_ok'));
        }

        return $dryRun ? self::SUCCESS : null;
    }

    /**
     * Print the reference half of the pre-flight: the header, and the INFO facts
     * as a table with a provenance marker on every row so an operator can tell a
     * probed fact from an assumed one. --preflight only; see the call site.
     *
     * @param  Report  $report
     */
    private function renderPreflightReport(array $report): void
    {
        $this->line(trans('scout::import.preflight_header', [
            'connection' => $report['connection'] ?? '(default)',
            'queue' => $report['probe']['queue_url']
                ?? $report['queue']
                ?? trans('scout::import.preflight_value_driver_default'),
        ]));

        if (! $report['enabled']) {
            $this->warn(trans('scout::import.preflight_disabled'));
        }

        $rows = [];
        foreach ($report['facts'] as $fact) {
            $rows[] = [$fact['label'], $fact['value'], $fact['provenance_label']];
        }

        $this->table([
            trans('scout::import.preflight_table_fact'),
            trans('scout::import.preflight_table_value'),
            trans('scout::import.preflight_table_provenance'),
        ], $rows);

        $this->line(trans('scout::import.preflight_hint'));
    }

    /**
     * Print the actionable half: warnings via warn(), proven fatals via error().
     * Printed on every --parallel run, not just a dry one — a finding is the
     * reason this check exists, and a run that is about to be re-delivered
     * mid-flight should say so whether or not anyone asked for the table.
     *
     * @param  Report  $report
     */
    private function renderPreflightFindings(array $report): void
    {
        foreach ($report['findings'] as $finding) {
            if ($finding['severity'] === QueueTimingPreflight::SEVERITY_FATAL) {
                $this->error($finding['message']);
            } else {
                $this->warn($finding['message']);
            }
        }
    }

    /**
     * The run's chunk size when --chunk names a usable one, so the pre-flight
     * can report it as declared rather than guessing from config.
     */
    private function chunkOption(): ?int
    {
        $chunk = $this->option('chunk');

        return is_numeric($chunk) && (int) $chunk > 0 ? (int) $chunk : null;
    }

    /**
     * The --profile-samples target when it names a usable one: how many chunks to
     * profile, spread across the whole plan.
     *
     * This is the ONLY switch that turns profiling on — a usable target here is
     * exactly what the `bool $profile` the jobs carry is derived from. A zero,
     * negative, empty or non-numeric value reads as "not provided", which leaves
     * the run unprofiled (handle() warns and carries on; a diagnostic knob never
     * aborts an import).
     */
    private function profileSamplesOption(): ?int
    {
        $samples = $this->option('profile-samples');

        // "all" is not a special mode, just the largest possible target: a target
        // above the chunk count degrades to a stride of 1 in
        // PullFromSource::profileStride(), i.e. every chunk. Case-insensitive and
        // trimmed because this is typed by hand on a terminal.
        if (is_string($samples) && strtolower(trim($samples)) === 'all') {
            return self::PROFILE_SAMPLES_ALL;
        }

        return is_numeric($samples) && (int) $samples > 0 ? (int) $samples : null;
    }

    /**
     * How many chunks --probe should measure, or null when --probe-samples names
     * no usable count (handle() warns and {@see DEFAULT_PROBE_SAMPLES} applies).
     */
    private function probeSamplesOption(): ?int
    {
        $samples = $this->option('probe-samples');

        return is_numeric($samples) && (int) $samples > 0 ? (int) $samples : null;
    }

    /**
     * How many workers the --probe estimate is divided across, or null when
     * --probe-workers names no usable count (handle() warns and
     * {@see DEFAULT_PROBE_WORKERS} applies).
     */
    private function probeWorkersOption(): ?int
    {
        $workers = $this->option('probe-workers');

        return is_numeric($workers) && (int) $workers > 0 ? (int) $workers : null;
    }

    /**
     * @param  array<int, mixed>  $argument
     * @return Collection<int, string>
     */
    private function searchableList(array $argument): Collection
    {
        return collect($argument)->filter(function ($searchable) {
            return is_string($searchable);
        })->values()->whenEmpty(function () {
            $factory = new SearchableListFactory(app()->getNamespace(), app()->path());

            return $factory->make();
        });
    }

    private function import(string $searchable): int
    {
        $sourceFactory = app(ImportSourceFactory::class);
        $source = $sourceFactory::from($searchable);

        // Per-run tuning. Only the built-in source supports it; a custom
        // ImportSource keeps its own chunking/planning.
        if ($source instanceof DefaultImportSource) {
            $chunk = $this->option('chunk');
            if ($chunk !== null) {
                $source = $source->withChunkSize((int) $chunk);
            }
            if ($this->option('fast-plan')) {
                $source = $source->withFastPlan();
            }
        }

        // --probe measures a handful of chunks and exits. It sits HERE, and not
        // in handle(), for two reasons: --chunk and --fast-plan have just been
        // applied to the source, so the probe measures the shape the real import
        // would use; and it returns before the lease is acquired, because a
        // probe is non-destructive and must neither block a real import nor be
        // blocked by one (it reports a lease it finds held as a warning instead).
        if ($this->option('probe')) {
            return $this->probe($source, $searchable);
        }

        $ttl = $this->intConfig('elasticsearch.import.lock_ttl', self::DEFAULT_LOCK_TTL);
        $owner = (new ImportLock($source->searchableAs(), $ttl))->acquire();

        // Another import for this exact model is already running. Skip this one
        // rather than racing its alias swap. Different models are unaffected —
        // each holds its own key. A skip is not a failure.
        if ($owner === null) {
            $this->warn(trans('scout::import.already_running', [
                'searchable' => $searchable,
                'key' => ImportLock::keyFor($source->searchableAs()),
                'ttl' => $ttl,
            ]));

            return self::SUCCESS;
        }

        // Once dispatch hands the lock to the pipeline, the pipeline owns
        // releasing it. Only
        // release here if dispatch itself failed before that handoff.
        $handedOff = false;

        // Resolved ONCE, because the jobs carry the sample target and a plain
        // "is this run profiled?" bool side by side, and the two must not be able
        // to disagree: profiling is on precisely when a usable target exists.
        $profileSamples = $this->profileSamplesOption();
        $profile = $profileSamples !== null;

        try {
            $startMessage = trans('scout::import.start', ['searchable' => "<comment>$searchable</comment>"]);
            $this->line($startMessage);

            if ($this->option('parallel')) {
                $connection = $this->resolvedConnection();
                $queue = $this->resolvedQueue();
                $token = (string) Str::uuid();
                $this->dispatchParallel($source, $connection, $queue, $owner, $ttl, $token, $profile, $profileSamples);
                $handedOff = true;

                if ($this->option('wait')) {
                    return $this->waitForRun($searchable, $token, $connection, $queue);
                }

                $this->output->success(trans('scout::import.done.queue', ['searchable' => $searchable]));

                return self::SUCCESS;
            }

            // Sequential path: scout.queue decides queued vs inline, and
            // per-model overrides are honoured. null falls back to the queue
            // driver's default.
            $connection = $this->option('connection') ?: $source->syncWithSearchUsing();
            $queue = $this->option('queue') ?: $source->syncWithSearchUsingQueue();

            $start = microtime(true);
            $this->dispatchSequential($source, $connection, $queue, $owner, $ttl, $profile, $profileSamples);
            $handedOff = true;

            // Queued sequential is fire-and-forget (runs on a worker), so it can
            // only report that the job was dispatched. An inline import finished
            // in-process, so report the same summary as --parallel --wait.
            if (config('scout.queue')) {
                $this->output->success(trans('scout::import.done.queue', ['searchable' => $searchable]));
            } else {
                $this->output->success(trans('scout::import.done_summary', [
                    'searchable' => $searchable,
                    'indexed' => $this->countIndexedDocuments($source->searchableAs()) ?? '?',
                    'elapsed' => $this->humanElapsed(microtime(true) - $start),
                ]));
            }

            return self::SUCCESS;
        } finally {
            if (! $handedOff) {
                ImportLock::release($source->searchableAs(), $owner);
            }
        }
    }

    /**
     * Measure a sample of chunks against a throwaway index, report, and exit.
     *
     * SUCCESS whenever the probe RAN, however alarming what it found: findings
     * are diagnostics, and an operator who asked "how slow is this?" must not
     * have the answer delivered as a failing exit code. FAILURE is reserved for
     * a probe that could not run at all — an unplannable source, or a probe
     * index that could not be created.
     */
    private function probe(ImportSource $source, string $searchable): int
    {
        $samples = $this->probeSamplesOption() ?? self::DEFAULT_PROBE_SAMPLES;
        $workers = $this->probeWorkersOption() ?? self::DEFAULT_PROBE_WORKERS;

        // The same timeout PullChunkJob diagnoses a chunk against, so "this
        // chunk is near its timeout" means the same thing here as it does in a
        // real import. Null when SCOUT_QUEUE_TIMEOUT is unset, which suppresses
        // the timeout rules rather than inventing a budget to compare against.
        $report = (new ImportProbe($source))->run($samples, Config::queueTimeout());

        return $this->renderProbe($report, $searchable, $workers);
    }

    /**
     * Print a probe report: the plan and the index it used, the per-sample
     * table, the aggregates, the extrapolation, then the findings with their
     * remedies — and finally the fate of the throwaway index, so an interrupted
     * probe still leaves a name an operator can act on.
     *
     * @param  ProbeReport  $report
     */
    private function renderProbe(array $report, string $searchable, int $workers): int
    {
        if (! $report['ok']) {
            $this->error($this->transLine('scout::import.probe_failed', [
                'searchable' => $searchable,
                'reason' => $report['error'] ?? '?',
            ]));
            $this->renderProbeWarnings($report['warnings']);

            return self::FAILURE;
        }

        if ($report['total_chunks'] === 0) {
            $this->line($this->transLine('scout::import.probe_empty', ['searchable' => $searchable]));
            $this->renderProbeWarnings($report['warnings']);

            return self::SUCCESS;
        }

        $this->line($this->transLine('scout::import.probe_header', [
            'searchable' => $searchable,
            'samples' => count($report['sampled']),
            'total' => $report['total_chunks'],
            'chunk' => $report['chunk_size'] ?? '?',
            'index' => $report['index'],
        ]));

        // Up front, before the numbers they qualify: a concurrent import means
        // every row below is inflated, and an operator reading the table needs
        // to know that before they act on it.
        $this->renderProbeWarnings($report['warnings']);

        $rows = [];
        foreach ($report['samples'] as $sample) {
            $metrics = $sample['metrics'];

            if ($metrics === null) {
                $this->warn($this->transLine('scout::import.probe_sample_failed', [
                    'chunk' => $sample['chunk_id'],
                    'reason' => $sample['error'] ?? '?',
                ]));

                continue;
            }

            $rows[] = [
                $sample['chunk_id'],
                $this->metric($metrics, 'fetched'),
                $this->metric($metrics, 'indexed'),
                $this->metric($metrics, 'fetch_ms'),
                $this->metric($metrics, 'filter_ms'),
                $this->metric($metrics, 'index_ms'),
                $this->metric($metrics, 'total_ms'),
                $this->metricQueries($metrics),
                $this->metric($metrics, 'payload_kb'),
            ];
        }

        if ($rows !== []) {
            $this->table([
                trans('scout::import.probe_table_chunk'),
                trans('scout::import.probe_table_fetched'),
                trans('scout::import.probe_table_indexed'),
                trans('scout::import.probe_table_fetch_ms'),
                trans('scout::import.probe_table_filter_ms'),
                trans('scout::import.probe_table_index_ms'),
                trans('scout::import.probe_table_total_ms'),
                trans('scout::import.probe_table_queries'),
                trans('scout::import.probe_table_payload_kb'),
            ], $rows);
        }

        $aggregates = $report['aggregates'];
        if ($aggregates['measured'] > 0) {
            $this->line($this->transLine('scout::import.probe_aggregates', [
                'searchable' => $searchable,
                'measured' => $aggregates['measured'],
                'min_ms' => (string) $aggregates['min_ms'],
                'median_ms' => (string) $aggregates['median_ms'],
                'mean_ms' => (string) $aggregates['mean_ms'],
                'max_ms' => (string) $aggregates['max_ms'],
                'fetched' => $aggregates['fetched'],
                'indexed' => $aggregates['indexed'],
            ]));
        }

        $estimate = $report['estimate'];
        if ($estimate !== null) {
            // The probe reports serial seconds only; dividing them across N
            // workers is the operator's own assumption, so it is applied here
            // where the option lives — and labelled an estimate either way.
            $workers = max(1, $workers);

            $this->line($this->transLine('scout::import.probe_estimate', [
                'measured' => $estimate['measured'],
                'chunks' => $estimate['chunks'],
                'mean_s' => (string) $estimate['mean_seconds'],
                'serial' => $this->humanElapsed($estimate['serial_seconds']),
                'parallel' => $this->humanElapsed($estimate['serial_seconds'] / $workers),
                'workers' => $workers,
            ]));

            // Only worth saying when the two means actually differ: it explains
            // why the estimate is built on a smaller number than the Total ms
            // column above, which otherwise looks like an arithmetic error.
            if ($estimate['overhead_seconds'] > 0.0) {
                $this->line($this->transLine('scout::import.probe_estimate_overhead', [
                    'mean_s' => (string) $estimate['mean_seconds'],
                    'overhead_s' => (string) $estimate['overhead_seconds'],
                    'measured_mean_s' => (string) $estimate['measured_mean_seconds'],
                ]));
            }
        }

        $this->renderProbeFindings($report, $searchable);

        // After the findings, because it answers the question they raise: the
        // findings say which phase owns the chunk, this says which statement owns
        // the phase. Printed even when no finding fired — a fetch that is merely
        // 55% of the chunk is under the dominance threshold and still worth a
        // look at the query behind it.
        $this->renderProbeSlowQueries($report, $searchable);

        // Named on the way out as well as in the header: an operator who ^C's a
        // probe never sees this line, and its absence is exactly the signal that
        // an index may have been left behind.
        if ($report['created'] && $report['torn_down']) {
            $this->line($this->transLine('scout::import.probe_cleaned_up', ['index' => $report['index']]));
        }

        return self::SUCCESS;
    }

    /**
     * The findings, each as a warn line with its remedy indented underneath.
     *
     * Rendered from the SAME profile_finding_<code> / _hint sentences the --wait
     * renderer uses, through the same two helpers: a diagnosis must read
     * identically whether it was measured by a worker during an import or by a
     * probe here, and one set of sentences per code is the only way to keep that
     * true. Findings NEVER touch the exit code.
     *
     * @param  ProbeReport  $report
     */
    private function renderProbeFindings(array $report, string $searchable): void
    {
        $findings = $report['findings'];

        if ($findings === []) {
            // A clean bill of health is only claimable when something was
            // actually measured: a probe whose every sample failed has found
            // nothing, which is not the same as having found nothing wrong.
            if ($report['aggregates']['measured'] > 0) {
                $this->line($this->transLine('scout::import.probe_no_findings', ['searchable' => $searchable]));
            }

            return;
        }

        $this->warn($this->transLine('scout::import.probe_findings_header', [
            'searchable' => $searchable,
            'findings' => count($findings),
        ]));

        foreach ($findings as $finding) {
            // The shape the shared renderers expect: the worst example measured
            // for this code, plus how many sampled chunks hit it.
            $example = [
                'count' => $finding['count'],
                'weight' => $finding['weight'],
                'data' => $finding['data'],
            ];

            $this->warn('  '.$this->transLine('scout::import.probe_finding_occurrences', [
                'count' => $finding['count'],
                'sampled' => count($report['sampled']),
                'message' => $this->profileFindingMessage($searchable, $finding['code'], $example),
            ]));
            $this->line('    '.$this->profileFindingHint($searchable, $finding['code'], $example));
            $this->renderProfileFindingSlowQuery($searchable, $finding['code'], $example, '      ');
        }
    }

    /**
     * The slowest query each phase ran, worst across every sampled chunk.
     *
     * This is the line the whole feature exists for. `--probe` already reports
     * that a chunk spent 258 seconds fetching and issued 5 queries; an operator
     * cannot act on that, because it never says WHICH query. The query log is
     * already enabled on a profiled chunk and already carries the statement and
     * its duration (PullFromSource::slowestQuery keeps the worst per phase), so
     * naming it here costs nothing extra to measure — only to print.
     *
     * The SQL is printed on a line of its own, verbatim and unwrapped by any
     * sentence, so it can be selected and pasted straight into EXPLAIN. It is the
     * statement only: `?` placeholders, never the bindings (see the lang file —
     * bound values are row data and must not reach a log or the run record).
     *
     * Everything is read defensively: `slow_query` arrives from a worker that may
     * run a different release of this package, and a report whose job is to
     * explain a slow import must not crash on a key that never arrived.
     *
     * @param  ProbeReport  $report
     */
    private function renderProbeSlowQueries(array $report, string $searchable): void
    {
        /** @var array<string, array{sql: string, ms: float, chunk: int}> $slowest */
        $slowest = [];

        foreach ($report['samples'] as $sample) {
            $metrics = $sample['metrics'];

            if ($metrics === null) {
                continue;
            }

            $captured = $metrics['slow_query'] ?? null;

            if (! is_array($captured)) {
                continue;
            }

            foreach (self::PROFILED_PHASES as $phase) {
                $entry = $captured[$phase] ?? null;

                // Null is the normal case for a phase that ran no query at all
                // (a filter reading only loaded attributes, an empty chunk).
                if (! is_array($entry)) {
                    continue;
                }

                $sql = $entry['sql'] ?? null;

                if (! is_string($sql) || $sql === '') {
                    continue;
                }

                $ms = $entry['ms'] ?? null;
                $ms = is_numeric($ms) ? (float) $ms : 0.0;

                // Strict >, so the earliest chunk reaching the worst time wins
                // and the block does not shuffle between identical runs.
                if (! isset($slowest[$phase]) || $ms > $slowest[$phase]['ms']) {
                    $slowest[$phase] = ['sql' => $sql, 'ms' => $ms, 'chunk' => $sample['chunk_id']];
                }
            }
        }

        if ($slowest === []) {
            return;
        }

        $this->line($this->transLine('scout::import.probe_slow_queries_header', [
            'searchable' => $searchable,
        ]));

        // Iterating the phase list rather than the map keeps the output in
        // fetch/filter/index order however the samples arrived.
        foreach (self::PROFILED_PHASES as $phase) {
            if (! isset($slowest[$phase])) {
                continue;
            }

            $this->line('  '.$this->transLine('scout::import.probe_slow_query', [
                'phase' => $this->transLine('scout::import.probe_slow_query_phase_'.$phase),
                'ms' => (string) round($slowest[$phase]['ms'], 1),
                'chunk' => $slowest[$phase]['chunk'],
            ]));
            $this->line('    '.$slowest[$phase]['sql']);
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function renderProbeWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
    }

    /**
     * One measured value as a table cell.
     *
     * Metrics are free-form diagnostic output rather than a schema (see
     * PullFromSource::lastProfile), so anything unreadable degrades to "?". A
     * report whose whole job is to explain a slow import must not be the thing
     * that crashes on a key that never arrived.
     *
     * @param  array<array-key, mixed>  $metrics
     */
    private function metric(array $metrics, string $key): string
    {
        $value = $metrics[$key] ?? null;

        return is_numeric($value) ? (string) $value : '?';
    }

    /**
     * The per-phase query counts as one cell, "fetch/filter/index".
     *
     * Split rather than summed because the split is the diagnosis: a count that
     * balloons in the index phase is an N+1 inside toSearchableArray(), the same
     * number ballooning in the filter phase is shouldBeSearchable() querying per
     * model, and the total on its own distinguishes neither.
     *
     * @param  array<array-key, mixed>  $metrics
     */
    private function metricQueries(array $metrics): string
    {
        $queries = $metrics['queries'] ?? null;

        if (! is_array($queries)) {
            return '?';
        }

        return $this->metric($queries, 'fetch')
            .'/'.$this->metric($queries, 'filter')
            .'/'.$this->metric($queries, 'index');
    }

    /**
     * Block until a --parallel run record reaches a terminal status.
     */
    private function waitForRun(string $searchable, string $token, ?string $connection, ?string $queue): int
    {
        $prepareKey = DispatchPullChunks::preparingKey($token);
        $start = microtime(true);
        $appearTimeout = $this->intConfig('elasticsearch.import.wait_timeout', self::DEFAULT_WAIT_TIMEOUT);
        $store = app(ImportRunStore::class);

        // Profiling diagnoses travel back from the workers through the run record
        // (see PullChunkJob::publishProfileFindings), and --wait is the only place
        // in this package where a process is still around to print them. Poll for
        // them ONLY when this run can actually have them: a plain --wait must not
        // pay a Redis round trip per iteration for a hash nobody ever writes.
        //
        // Codes already announced, so a finding is introduced once and does not
        // reappear on every poll as its occurrence count climbs. Local to this
        // call, not the instance: each searchable reports its own run.
        $findingsExpected = $this->profileFindingsExpected();
        $printedFindings = [];

        // Wait for the worker to run the prepare stages + DispatchPullChunks and
        // publish the run record. A worker first has to pick up the chain, clean
        // up + create the new index, and scan the primary keys to plan the
        // chunks before the run record exists.
        //
        // The timeout is applied to the time since the last *observed activity*,
        // not since dispatch: each prepare stage publishes a heartbeat as it
        // begins (see StageJob::withHeartbeat + DispatchPullChunks), so a chain
        // that is actively progressing keeps resetting the window and never
        // false-times-out just because a busy queue took a while to schedule
        // each hop. Two distinct give-up cases: never picked up (no heartbeat
        // ever — likely no worker on this queue, or a very deep backlog) vs.
        // picked up then went silent (a slow stage, or a crashed/OOM worker).
        $lastActivity = $start;
        $lastSeq = null;
        $lastStage = null;
        $pickedUp = false;

        while (($snapshot = $store->snapshot($token))['status'] === null) {
            $beat = Cache::get($prepareKey);
            if (is_array($beat) && ($beat['seq'] ?? null) !== $lastSeq) {
                $lastSeq = $beat['seq'] ?? null;
                $lastStage = is_string($beat['stage'] ?? null) ? $beat['stage'] : null;
                $lastActivity = microtime(true);
                $pickedUp = true;
                $this->comment(trans('scout::import.wait_preparing', [
                    'searchable' => $searchable,
                    'stage' => $lastStage ?? '…',
                ]));
            }

            if (microtime(true) - $lastActivity > $appearTimeout) {
                $silence = (int) round(microtime(true) - $lastActivity);
                if ($pickedUp) {
                    $this->warn(trans('scout::import.wait_prepare_stalled', [
                        'searchable' => $searchable,
                        'stage' => $lastStage ?? '?',
                        'elapsed' => $silence,
                    ]));
                    $this->line(trans('scout::import.wait_prepare_stalled_hint'));
                } else {
                    $this->warn(trans('scout::import.wait_no_pickup', [
                        'searchable' => $searchable,
                        'connection' => $connection ?? '(driver default)',
                        'queue' => $queue ?? '(driver default)',
                        'elapsed' => $silence,
                        'timeout' => $appearTimeout,
                    ]));
                    $this->line(trans('scout::import.wait_no_pickup_hint'));
                }

                return self::SUCCESS;
            }

            usleep(500000);
        }

        $lastLine = null;
        while (in_array($snapshot['status'], [
            ImportRunStore::STATUS_RUNNING,
            ImportRunStore::STATUS_FINALIZING,
        ], true)) {
            $line = trans('scout::import.wait_running', [
                'searchable' => $searchable,
                'done' => $snapshot['done'],
                'total' => $snapshot['total'],
                'status' => $snapshot['status'],
            ]);
            if ($line !== $lastLine) {
                $this->line($line);
                $lastLine = $line;
            }

            // Rides the existing 500ms progress cadence rather than a timer of its
            // own: the read is a couple of small hashes bounded by the findings cap,
            // and a diagnosis is worth most while the operator can still stop the
            // import. The prepare loop above deliberately does not poll — no chunk
            // has run yet at that point, so no finding can exist.
            if ($findingsExpected) {
                $this->renderNewProfileFindings($store, $token, $searchable, $printedFindings);
            }

            usleep(500000);
            $snapshot = $store->snapshot($token);
        }

        $elapsed = $this->humanElapsed(microtime(true) - $start);
        $total = (int) $snapshot['total'];

        // Every finding once more, with its occurrence count, before the outcome
        // line — the last poll above can miss a chunk that published between it
        // and the terminal transition, and that chunk is often the interesting
        // one. Printed for a succeeded run too: an import that finished cleanly
        // while lazy-loading a relation per model is still worth reporting.
        // Findings are DIAGNOSTICS and cannot change the code returned below.
        if ($findingsExpected) {
            $this->renderProfileFindingsSummary($store, $token, $searchable, $printedFindings);
        }

        if ($snapshot['status'] === ImportRunStore::STATUS_FAILED) {
            $this->error(trans('scout::import.wait_failed', [
                'searchable' => $searchable,
                'chunks' => $total,
                'elapsed' => $elapsed,
            ]));

            return self::FAILURE;
        }

        if ($snapshot['status'] === ImportRunStore::STATUS_FINALIZE_FAILED) {
            $this->error(trans('scout::import.wait_finalize_failed', [
                'searchable' => $searchable,
                'chunks' => $total,
                'elapsed' => $elapsed,
            ]));

            return self::FAILURE;
        }

        if ($total === 0) {
            $this->output->success(trans('scout::import.wait_summary_empty', ['searchable' => $searchable]));

            return self::SUCCESS;
        }

        $indexed = $snapshot['index'] !== null ? $this->countIndexedDocuments($snapshot['index']) : null;
        $this->output->success(trans('scout::import.wait_summary', [
            'searchable' => $searchable,
            'indexed' => $indexed ?? '?',
            'chunks' => $total,
            'elapsed' => $elapsed,
        ]));

        return self::SUCCESS;
    }

    /**
     * Whether profiling findings can exist for this run, i.e. whether it is worth
     * polling the run record for them.
     *
     * Two conditions, both necessary: profiling was requested, i.e.
     * --profile-samples named a usable target (a count, or "all" for every
     * chunk); and publication is not switched off wholesale —
     * SCOUT_IMPORT_PROFILE_FINDINGS=0 makes the workers write nothing, so reading
     * would be pure waste.
     *
     * Reads the options defensively. Every other option read in this class happens
     * on the normal Artisan path, where the input is always bound; this one is
     * reached from waitForRun(), which is also driven directly (by reflection) with
     * only the output wired up. An unbound input means "no options were given",
     * which for a diagnostic read is exactly the safe answer — and it keeps a
     * findings poll from ever being the thing that breaks a --wait run.
     */
    private function profileFindingsExpected(): bool
    {
        if ($this->input === null) {
            return false;
        }

        if ($this->profileSamplesOption() === null) {
            return false;
        }

        return $this->intConfig('elasticsearch.import.profile_findings', self::DEFAULT_PROFILE_FINDINGS) > 0;
    }

    /**
     * Print every finding whose code has not been announced yet: the diagnosis at
     * warn level, then its remedy on an indented line.
     *
     * @param  array<string, bool>  $printed  Codes already announced; grows here
     */
    private function renderNewProfileFindings(ImportRunStore $store, string $token, string $searchable, array &$printed): void
    {
        foreach ($this->pollProfileFindings($store, $token) as $code => $finding) {
            if (isset($printed[$code])) {
                continue;
            }

            $printed[$code] = true;

            $this->warn($this->profileFindingMessage($searchable, $code, $finding));
            $this->line('  '.$this->profileFindingHint($searchable, $code, $finding));
            $this->renderProfileFindingSlowQuery($searchable, $code, $finding, '    ');
        }
    }

    /**
     * Print the closing roll-up: one line per finding, carrying how many chunks
     * hit it and the worst example measured.
     *
     * @param  array<string, bool>  $printed  Codes already announced; grows here
     */
    private function renderProfileFindingsSummary(ImportRunStore $store, string $token, string $searchable, array &$printed): void
    {
        $findings = $this->pollProfileFindings($store, $token);

        // Nothing found is worth saying nothing about: a clean profiled run should
        // look exactly like a clean unprofiled one.
        if ($findings === []) {
            return;
        }

        $this->warn($this->transLine('scout::import.profile_findings_header', [
            'searchable' => $searchable,
            'findings' => count($findings),
        ]));

        foreach ($findings as $code => $finding) {
            $this->warn('  '.$this->transLine('scout::import.profile_finding_occurrences', [
                'count' => $finding['count'],
                'message' => $this->profileFindingMessage($searchable, $code, $finding),
            ]));

            // The remedy was printed when the code first appeared, so repeat it
            // only for a code that never got an inline line — one published
            // between the last poll and the terminal status. A late finding must
            // never be reported without the fix that goes with it.
            if (! isset($printed[$code])) {
                $printed[$code] = true;
                $this->line('    '.$this->profileFindingHint($searchable, $code, $finding));
                $this->renderProfileFindingSlowQuery($searchable, $code, $finding, '      ');
            }
        }
    }

    /**
     * Read the run's published findings, or none at all when that read fails.
     *
     * A DIAGNOSTIC MUST NEVER FAIL AN IMPORT — the same rule the publishing side
     * obeys (see PullChunkJob::publishProfileFindings), applied to the reading
     * side. This runs inside the poll loop of a run that is otherwise perfectly
     * healthy, so a Redis hiccup or a payload this release cannot make sense of
     * has to cost the operator a diagnosis, never the exit code of a finished
     * import.
     *
     * @return array<string, array{count:int, weight:float, data:array<string, scalar>}>
     */
    private function pollProfileFindings(ImportRunStore $store, string $token): array
    {
        try {
            return $store->profileFindings($token);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * One finding as a sentence naming the numbers that justify it.
     *
     * @param  array{count:int, weight:float, data:array<string, scalar>}  $finding
     */
    private function profileFindingMessage(string $searchable, string $code, array $finding): string
    {
        $key = 'scout::import.profile_finding_'.$code;

        // A code this release has no sentence for: a worker running a newer
        // version of the package published it. Report it verbatim rather than
        // dropping the finding (or printing a raw translation key at the
        // operator).
        if (! Lang::has($key)) {
            return $this->transLine('scout::import.profile_finding_unknown', [
                'searchable' => $searchable,
                'code' => $code,
                'data' => $this->describeFindingData($finding['data']),
            ]);
        }

        return $this->transLine($key, $this->findingReplacements($searchable, $code, $finding));
    }

    /**
     * The remedy line that goes with a finding.
     *
     * Looked up independently of the message so a code carrying one and not the
     * other still prints something useful.
     *
     * @param  array{count:int, weight:float, data:array<string, scalar>}  $finding
     */
    private function profileFindingHint(string $searchable, string $code, array $finding): string
    {
        $key = 'scout::import.profile_finding_'.$code.'_hint';

        if (! Lang::has($key)) {
            return $this->transLine('scout::import.profile_finding_unknown_hint');
        }

        return $this->transLine($key, $this->findingReplacements($searchable, $code, $finding));
    }

    /**
     * The extra line under a remedy naming the query the dominant phase spent its
     * time in — nothing at all when the finding carries no query.
     *
     * The third renderer sharing profile_finding_* sentences between --wait and
     * --probe, for the same reason as the other two: a diagnosis must read
     * identically wherever it was measured. Only fetch_dominant and
     * index_dominant carry `slow_sql` (see ProfileDiagnostics), so every other
     * code silently prints nothing here rather than needing a branch per code.
     *
     * The existing finding message and hint are deliberately left untouched: a
     * 300-character statement does not belong inside a sentence an operator reads
     * on every occurrence. $indent puts it under the hint of whichever caller it
     * is, which is the only thing that differs between the three.
     *
     * PRIVACY: `slow_sql` is the statement with `?` placeholders. Query bindings
     * are row data (emails, names, tokens) and are never captured upstream, so
     * there is nothing to redact here — and nothing in this class may start
     * reading them.
     *
     * @param  array{count:int, weight:float, data:array<string, scalar>}  $finding
     */
    private function renderProfileFindingSlowQuery(string $searchable, string $code, array $finding, string $indent): void
    {
        $sql = $finding['data']['slow_sql'] ?? null;

        // Absent for every code but the two dominance findings, and absent even
        // for those when the phase logged no query. An empty string is treated as
        // absent too: a log entry with no usable statement is not worth a line.
        if (! is_string($sql) || $sql === '') {
            return;
        }

        $replacements = $this->findingReplacements($searchable, $code, $finding);

        // The sentence names :ms and :sql; the published data names them slow_ms
        // and slow_sql. Aliased rather than renamed on either side — the data keys
        // travel between package versions in the run record, and the sentence
        // reads better short. Both spellings are seeded with "?" by
        // PROFILE_FINDING_PLACEHOLDERS, so a missing slow_ms degrades to "?".
        $replacements['sql'] = $replacements['slow_sql'];
        $replacements['ms'] = $replacements['slow_ms'];

        $this->line($indent.$this->transLine('scout::import.profile_finding_slow_query', $replacements));
    }

    /**
     * Placeholder values for one finding: every known placeholder as "?", then the
     * run's own facts and whatever the worker actually measured on top.
     *
     * @param  array{count:int, weight:float, data:array<string, scalar>}  $finding
     * @return array<string, string>
     */
    private function findingReplacements(string $searchable, string $code, array $finding): array
    {
        $replacements = [];

        foreach (self::PROFILE_FINDING_PLACEHOLDERS as $placeholder) {
            $replacements[$placeholder] = '?';
        }

        foreach ($finding['data'] as $field => $value) {
            $replacements[$field] = $this->scalarToString($value);
        }

        $replacements['searchable'] = $searchable;
        $replacements['code'] = $code;
        $replacements['count'] = (string) $finding['count'];

        return $replacements;
    }

    /**
     * A finding's raw data as "key=value, key=value", used only when the code
     * itself is unknown to this release and there is no sentence to put it in.
     *
     * @param  array<string, scalar>  $data
     */
    private function describeFindingData(array $data): string
    {
        if ($data === []) {
            return $this->transLine('scout::import.profile_finding_no_data');
        }

        $parts = [];
        foreach ($data as $field => $value) {
            $parts[] = $field.'='.$this->scalarToString($value);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  scalar  $value
     */
    private function scalarToString($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * trans() constrained to a single line of output: the translator can hand back
     * an array (a whole group, or a badly overridden key), and a published
     * diagnosis is not worth an "array to string" crash in a finished import.
     *
     * @param  array<string, string|int>  $replace
     */
    private function transLine(string $key, array $replace = []): string
    {
        $line = trans($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * Count documents in the freshly built index by its concrete name, so the
     * total is independent of the alias swap timing. Best effort — returns null
     * if the index is unavailable.
     */
    private function countIndexedDocuments(string $index): ?int
    {
        try {
            $client = app(Client::class);
            $client->indices()->refresh(['index' => $index]);

            return (int) ($client->count(['index' => $index])['count'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function humanElapsed(float $seconds): string
    {
        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.'m '.($seconds % 60).'s';
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    private function dispatchSequential(ImportSource $source, ?string $connection, ?string $queue, string $owner, int $ttl, bool $profile = false, ?int $profileSamples = null): void
    {
        $job = new Import($source, $owner, $ttl, $profile, $profileSamples);
        $job->timeout = Config::queueTimeout();

        if (config('scout.queue')) {
            $job = (new QueueableJob())->chain([$job]);
            $job->timeout = Config::queueTimeout();
        }

        $bar = (new ProgressBarFactory($this->output))->create();
        $job->withProgressReport($bar);

        dispatch($job)->allOnQueue($queue)->allOnConnection($connection);
    }

    private function dispatchParallel(ImportSource $source, ?string $connection, ?string $queue, string $owner, int $ttl, string $progressToken, bool $profile = false, ?int $profileSamples = null): void
    {
        $index = Index::fromSource($source);
        $timeout = Config::queueTimeout();

        // Pass the lock owner so CleanUp refuses to delete a write index once
        // our lease has lapsed, and renew the lease as each prepare stage begins
        // so the (otherwise unrenewed) clean-up + create-index + planning window
        // cannot expire and admit a second, overlapping run of the same model.
        $cleanUp = (new StageJob(new CleanUp($source, $owner)))
            ->withLockRenew($source->searchableAs(), $owner, $ttl);
        $createIndex = (new StageJob(new CreateWriteIndex($source, $index)))
            ->withLockRenew($source->searchableAs(), $owner, $ttl);

        $prepareKey = DispatchPullChunks::preparingKey($progressToken);
        $cleanUp->withHeartbeat($prepareKey, 1, $ttl);
        $createIndex->withHeartbeat($prepareKey, 2, $ttl);

        $stages = [
            $cleanUp,
            $createIndex,
            new DispatchPullChunks($source, $index, $connection, $queue, $owner, $ttl, $progressToken, $profile, 0, null, $profileSamples),
        ];

        foreach ($stages as $stage) {
            $stage->timeout = $timeout;
        }

        Bus::chain($stages)
            ->onConnection($connection)
            ->onQueue($queue)
            ->catch(function (\Throwable $e) use ($source, $index, $owner) {
                report($e);
                RollbackImportJob::removeUnpromotedIndex($source, $index, $owner);
                ImportLock::release($source->searchableAs(), $owner);
            })
            ->dispatch();
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
