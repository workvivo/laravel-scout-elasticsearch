<?php

return [
    'start' => 'Importing [:searchable]',
    'done' => 'All [:searchable] records have been imported.',
    'done_summary' => '[:searchable] imported: :indexed documents in :elapsed.',
    'done.queue' => 'Import job dispatched to the queue.',
    'already_running' => 'An import for [:searchable] is already running (lock key: :key, inactivity TTL :ttl s). Skipping. If you are sure no run is in flight, clear it with `php artisan tinker` -> `Cache::forget(\':key\')`.',
    'parallel_requires_async_queue' => 'The --parallel option needs an asynchronous queue, but connection [:connection] uses the sync driver. Pass --connection, set your default queue, or use --force to run inline.',
    'parallel_requires_redis' => 'The --parallel option requires Redis-backed import coordination.',
    'parallel_requires_atomic_lock_store' => 'The --parallel option requires an atomic cache store for import locks; the file cache driver is not supported.',
    'invalid_chunk' => 'The --chunk option must be a positive integer.',
    'invalid_profile_samples' => 'The --profile-samples option must be a positive integer or "all" and was ignored, so this import is not profiled. Pass --profile-samples=all to profile every chunk.',
    'wait_needs_parallel' => 'The --wait option only applies to --parallel imports and was ignored.',
    'indexing' => 'Indexing [:searchable]',
    'wait_preparing' => 'Preparing [:searchable]: :stage…',
    'wait_no_pickup' => 'Dispatched [:searchable] to connection [:connection], queue [:queue], but no worker started importing it within :elapsed s (SCOUT_IMPORT_WAIT_TIMEOUT=:timeout). It is still queued.',
    'wait_no_pickup_hint' => 'Confirm a queue worker is consuming that connection/queue. On a deep or busy queue the prepare chain can wait a while to be picked up — raise SCOUT_IMPORT_WAIT_TIMEOUT.',
    'wait_prepare_stalled' => '[:searchable] was picked up (:stage) but produced no run record within :elapsed s. The prepare stage may be slow, or the worker crashed/was killed (OOM). It is still queued.',
    'wait_prepare_stalled_hint' => 'Check the worker logs and memory limit. If the stage is simply slow (large table without --fast-plan), raise SCOUT_IMPORT_WAIT_TIMEOUT.',
    'wait_running' => '[:searchable] import :status: :done/:total chunks done.',
    'wait_summary' => '[:searchable] imported: :indexed documents across :chunks chunks in :elapsed.',
    'wait_summary_empty' => '[:searchable] had no records to import.',
    'wait_failed' => '[:searchable] import failed across :chunks chunks after :elapsed. The previous index is still serving.',
    'wait_finalize_failed' => '[:searchable] import finalize failed after :chunks chunks in :elapsed. The previous index may still be serving; check worker logs.',

    // --- --profile-samples findings rendered during --wait (ProfileDiagnostics) -
    // Each finding is one profiled chunk's diagnosis, published into the run record
    // by the worker that measured it and printed here — the only place a process is
    // still attached to the operator's terminal. Every message names the measured
    // numbers; the paired _hint line names the fix and nothing else. They are
    // DIAGNOSTICS: they never change the command's exit code. Placeholders come
    // from the finding's own data, so a value that never arrived prints as "?".
    'profile_findings_header' => 'Profiling findings for [:searchable] (:findings distinct, diagnostics only — the import status above is unaffected):',
    'profile_finding_occurrences' => ':count chunk(s): :message',
    'profile_finding_unknown' => 'Profiling reported [:code] for [:searchable], a finding this version does not know how to explain (:data).',
    'profile_finding_unknown_hint' => 'The worker that published it runs a newer laravel-scout-elasticsearch than this host; upgrade the package here to see the diagnosis and its remedy.',
    'profile_finding_no_data' => 'no measurements published',

    'profile_finding_n_plus_one' => 'N+1 while indexing [:searchable]: :relation was lazy-loaded :loads times inside one chunk (:relations relation(s) lazy-loaded).',
    'profile_finding_n_plus_one_hint' => 'Each of those :loads loads is an extra query per model: eager-load the relation in makeAllSearchableUsing() on the model, e.g. `return $query->with([...]);`.',
    'profile_finding_chunk_exceeds_timeout' => 'A chunk of [:searchable] took :total_ms ms, at or past its :timeout s job timeout (:pct% of the budget) — that chunk is being killed mid-flight.',
    'profile_finding_chunk_exceeds_timeout_hint' => 'Raise SCOUT_QUEUE_TIMEOUT above your p99 chunk (and keep the queue VisibilityTimeout above that), or lower --chunk until a chunk finishes well inside the timeout.',
    'profile_finding_chunk_near_timeout' => 'A chunk of [:searchable] took :total_ms ms, :pct% of its :timeout s job timeout — normal variance will push a slower chunk over.',
    'profile_finding_chunk_near_timeout_hint' => 'Raise SCOUT_QUEUE_TIMEOUT above your p99 chunk (and keep the queue VisibilityTimeout above that), or lower --chunk to buy headroom before a chunk trips the timeout.',
    'profile_finding_filter_queries' => 'shouldBeSearchable() ran :queries queries for one chunk of :fetched [:searchable] models, i.e. it queries per model.',
    'profile_finding_filter_queries_hint' => 'Decide from attributes already loaded on the model, or eager-load whatever it reads in makeAllSearchableUsing(), so the filter costs one query per chunk.',
    'profile_finding_fetch_dominant' => 'Fetching dominates [:searchable]: :fetch_ms ms of :total_ms ms (:pct%) went to reading rows from the database.',
    'profile_finding_fetch_dominant_hint' => 'Index the columns the keyset scan and eager-loads sort/join on, drop with() relations the index does not need, and try --fast-plan; a smaller --chunk will not help while the read is the bottleneck.',
    'profile_finding_index_dominant' => 'Indexing dominates [:searchable]: :index_ms ms of :total_ms ms (:pct%), of which :bulk_ms ms was the bulk request itself.',
    'profile_finding_index_dominant_hint' => 'Split by those two numbers: bulk-heavy is the cluster (raise the write index refresh_interval, drop replicas for the import), the remainder is CPU spent in toSearchableArray().',
    'profile_finding_large_payload' => 'Large documents in [:searchable]: :avg_kb KB average across :indexed documents (:payload_kb KB in one chunk).',
    'profile_finding_large_payload_hint' => 'Trim toSearchableArray() — whole relations, blobs and casts are the usual leaks — or lower --chunk so each bulk request stays small.',

    // Shared by --wait and --probe, printed under the remedy of the two findings
    // that make an operator ask "which query?" (fetch_dominant, index_dominant):
    // the slowest statement that phase actually ran, straight from the query log
    // the profiled chunk already collected.
    //
    // PRIVACY: this is the STATEMENT ONLY, with `?` placeholders. The bindings —
    // real row data: emails, names, tokens — are never captured, never stored in
    // the run record and never rendered, here or anywhere else. Say so on the
    // line itself: this SQL is copied into tickets and EXPLAIN, and the operator
    // pasting it deserves to know it carries no customer data.
    'profile_finding_slow_query' => 'Slowest query in that phase: :ms ms — :sql (statement only: `?` placeholders, no bound values are ever captured)',

    // --- --probe (ImportProbe) -----------------------------------------------
    // Chrome only. The findings a probe prints are the SAME diagnoses a --wait run
    // prints, rendered from the profile_finding_<code> / _hint keys above — there
    // is exactly one set of sentences per code, wherever it was measured. A probe
    // imports nothing: it writes a few sampled chunks into a throwaway index that
    // is never added to the alias, deletes it, and exits. Findings are diagnostics
    // and never change its exit code.
    'invalid_probe_samples' => 'The --probe-samples option must be a positive integer and was ignored; --probe measures :default chunks.',
    'invalid_probe_workers' => 'The --probe-workers option must be a positive integer and was ignored; the --probe estimate assumes :default worker.',
    'probe_header' => 'Probing [:searchable]: measuring :samples of :total chunk(s) (~:chunk rows each) into throwaway index [:index]. Nothing is imported — the live index is never written to and its alias is never touched.',
    'probe_empty' => '[:searchable] has nothing to probe: the chunk plan is empty, so no chunk was measured and no probe index was created.',
    'probe_failed' => 'Could not probe [:searchable]: :reason',
    'probe_sample_failed' => 'Chunk :chunk was not measured: :reason',
    'probe_chunk_unmeasured' => 'the chunk ran but produced no measurements',
    'probe_chunk_missing' => 'the chunk is no longer part of the plan',
    'probe_table_chunk' => 'Chunk',
    'probe_table_fetched' => 'Fetched',
    'probe_table_indexed' => 'Indexed',
    'probe_table_fetch_ms' => 'Fetch ms',
    'probe_table_filter_ms' => 'Filter ms',
    'probe_table_index_ms' => 'Index ms',
    'probe_table_total_ms' => 'Total ms',
    'probe_table_queries' => 'Queries fetch/filter/index',
    'probe_table_payload_kb' => 'Payload KB',
    'probe_aggregates' => 'Measured :measured chunk(s) of [:searchable]: min :min_ms ms, median :median_ms ms, mean :mean_ms ms, max :max_ms ms (:fetched rows fetched, :indexed indexed).',
    'probe_estimate' => 'ESTIMATE from :measured sampled chunk(s), not a measurement: :chunks chunks x :mean_s s mean = :serial on one worker, :parallel across :workers worker(s). Chunk cost is never uniform, so a heavy tail pushes this up.',
    'probe_estimate_overhead' => 'The :mean_s s mean excludes :overhead_s s per chunk of profiling overhead (measured mean was :measured_mean_s s): profiling serializes every document one extra time to separate serialize_ms from bulk_ms, and an import never pays that.',
    // The block that turns "the read is slow" into a query and a missing index.
    // The probe already logs one query per phase per sampled chunk; this reports
    // the worst of them, per phase, so the operator has something to paste into
    // EXPLAIN instead of a query count. Same privacy rule as above and stated
    // again here, because this block is the one people copy out of the terminal:
    // the SQL is the statement with `?` placeholders and nothing else.
    'probe_slow_queries_header' => 'Slowest query per phase across the sampled chunks of [:searchable] — paste each into EXPLAIN. A cost that barely changes between chunks in different id ranges is a full scan, i.e. a missing index. Statement only: `?` placeholders, no bound values are ever captured, so nothing below carries row data.',
    'probe_slow_query' => ':phase, :ms ms (chunk :chunk):',
    'probe_slow_query_phase_fetch' => 'Fetch',
    'probe_slow_query_phase_filter' => 'Filter',
    'probe_slow_query_phase_index' => 'Index',
    'probe_findings_header' => 'Probe findings for [:searchable] (:findings distinct — diagnostics only, --probe exits successfully either way):',
    'probe_finding_occurrences' => ':count of :sampled sampled chunk(s): :message',
    'probe_no_findings' => 'No findings for [:searchable]: nothing in the sampled chunks looks pathological.',
    'probe_cleaned_up' => 'Probe index [:index] deleted. Nothing else was created and no import ran.',
    'probe_warning_import_running' => 'An import for [:searchable] appears to be running (lock key: :key), so it competes for the same rows and the same cluster and every number below is skewed by it. Probe again once it has finished.',
    'probe_warning_teardown_failed' => 'The probe index [:index] could not be deleted (:reason). It is not in the alias and nothing reads it, but it still occupies disk — remove it by hand: `DELETE /:index`.',

    // --- --parallel queue-timing pre-flight (QueueTimingPreflight) -----------
    // The invariant being checked, in one line:
    //   p99 chunk << effective job timeout < redelivery window < shutdown grace
    'preflight_header' => 'Queue timing pre-flight for connection [:connection], queue [:queue]:',
    'preflight_ok' => 'Queue timing pre-flight passed: no blocking problems found.',
    'preflight_abort' => 'Queue timing pre-flight failed. A chunk cannot survive its own re-delivery window on this queue, so the import would fail chunks before they ever run. Fix the settings above, or pass --force to run anyway.',
    'preflight_forced' => 'Queue timing pre-flight found blocking problems, but --force was passed: continuing anyway. Expect chunks to be re-delivered and failed before they run.',
    'preflight_hint' => 'Only probed facts can be proven. Values marked "assumed default" or "unknown" are not visible from inside PHP — declare them with SCOUT_IMPORT_WORKER_TIMEOUT, SCOUT_IMPORT_SHUTDOWN_GRACE and SCOUT_IMPORT_EXPECTED_CHUNK_SECONDS (measure a chunk with --profile-samples=all, or --probe) so this check can reason about them.',
    'preflight_table_fact' => 'Setting',
    'preflight_table_value' => 'Value',
    'preflight_table_provenance' => 'Provenance',
    // Emitted by the command rather than the checker: --force waives the check
    // exactly as it waives the sync-connection abort, --preflight is the dry-run
    // form, and the master switch can turn the whole thing off.
    'preflight_needs_parallel' => 'The --preflight option only applies to --parallel imports and was ignored.',
    'preflight_skipped_forced' => 'Queue timing pre-flight skipped: --force was passed, so the queue re-delivery window was never checked against the import job timeout. Run with --parallel --preflight to see the report without importing.',
    'preflight_disabled' => 'Queue timing pre-flight is disabled (SCOUT_IMPORT_PREFLIGHT=false): the settings below are reported as resolved, but nothing was checked.',

    // Fatal findings. The two VisibilityTimeout races require a successfully
    // probed VisibilityTimeout — nothing else is proven, so nothing else is
    // fatal. The one exception is the SQS ceiling finding below: an effective job
    // timeout above the AWS 12-hour cap makes the ordering unsatisfiable on every
    // conceivable queue, so configuration alone proves it and it fires with no
    // probe. It also replaces the two messages below, whose "raise
    // VisibilityTimeout to :suggested s" remedy AWS would reject up there.
    'preflight_timeout_above_sqs_ceiling' => 'The :timeout s job timeout in force (:source) is above :ceiling s, the largest VisibilityTimeout SQS accepts (12 hours — an AWS hard cap). On queue [:queue] the ordering this import depends on, job timeout below re-delivery window, therefore has no legal solution at all: every chunk still running after :ceiling s is re-delivered while the first copy works, and because SQS reports ApproximateReceiveCount as the attempt number that second copy arrives already exhausted and the worker fails it before the job body runs. No queue probe is needed to know this — it follows from the configuration on any queue. Do not raise VisibilityTimeout; AWS will refuse a value above :ceiling s. Lower the effective job timeout instead: set SCOUT_QUEUE_TIMEOUT to at most :suggested s, and lower the queue worker\'s `--timeout` (usually the real cause — `queue:work sqs --timeout=86400` is common, it governs whenever SCOUT_QUEUE_TIMEOUT is unset, and it cannot be read from PHP; declare it with SCOUT_IMPORT_WORKER_TIMEOUT so this check can see it) below :ceiling s as well. Then set the queue VisibilityTimeout above the new job timeout.',
    'preflight_sqs_visibility_below_timeout' => 'SQS queue [:queue] has VisibilityTimeout :visibility s, which is not above the import job timeout :timeout s (SCOUT_QUEUE_TIMEOUT). SQS will make a second copy of every chunk that runs longer than :visibility s available while the first copy is still working, and because SQS reports ApproximateReceiveCount as the attempt number, that copy arrives already exhausted and the worker fails it before the job body runs. Raise the queue VisibilityTimeout to at least :suggested s, or lower SCOUT_QUEUE_TIMEOUT below :visibility s.',
    'preflight_sqs_visibility_below_worker_timeout' => 'SQS queue [:queue] has VisibilityTimeout :visibility s, which is not above the :timeout s job timeout in force (:source). SCOUT_QUEUE_TIMEOUT is not set, so the import jobs carry no alarm of their own and the worker timeout governs: SQS re-delivers each slow chunk before anything stops the first copy, and the re-delivery arrives with an attempt count that fails it before the job body runs. Two remedies, either one is enough: raise the queue VisibilityTimeout to at least :suggested s, or set SCOUT_QUEUE_TIMEOUT below :visibility s so the job\'s own alarm fires first and the message is failed and deleted cleanly.',

    // Warnings. Never abort: they describe what could not be proven, or what is
    // ordered correctly but tightly.
    'preflight_queue_probe_unavailable' => 'Could not read the SQS attributes of queue [:queue] (:reason), so the re-delivery ordering is unverified. Confirm by hand that the queue VisibilityTimeout is comfortably above the job timeout.',
    'preflight_job_timeout_unset' => 'SCOUT_QUEUE_TIMEOUT is not set, so the import jobs carry no timeout of their own and the queue worker\'s --timeout governs instead: assuming :timeout s (:source). That value lives in another process and cannot be read from here. Set SCOUT_QUEUE_TIMEOUT so the job alarm — the path that fails and deletes a message cleanly — is the one that fires first.',
    'preflight_retry_until_set' => 'SCOUT_IMPORT_RETRY_UNTIL is set to :retry_until s. It silently disables tries (:tries): the worker only compares attempts against maxTries when no retry deadline is set, so a failing chunk retries without bound until the deadline. It is also stamped once at dispatch, so on a large fan-out every chunk still queued when the deadline passes fails on its first receive, having never run. Set it to 0 unless you specifically need a deadline.',
    'preflight_sqs_max_receive_count_below_tries' => 'SQS queue [:queue] has a redrive policy with maxReceiveCount :max_receive_count, which does not exceed tries :tries. The dead-letter queue will swallow a message the worker still intended to retry. Raise maxReceiveCount to at least :suggested, or lower SCOUT_IMPORT_RETRY_TRIES.',
    'preflight_sqs_missing_dead_letter' => 'SQS queue [:queue] has no dead-letter target, so a chunk whose deliveries are exhausted disappears with no trace and the run can stall with no failure to look at. Attach a dead-letter queue via a redrive policy.',
    'preflight_lock_ttl_below_timeout' => 'The import lease TTL :lock_ttl s is not above the :timeout s job timeout, so the lease can lapse while a single chunk is still running and admit a second, overlapping import of the same model. Raise SCOUT_IMPORT_LOCK_TTL well above the time one chunk takes.',
    'preflight_shutdown_grace_below_timeout' => 'The declared shutdown grace :grace s is below the :timeout s job timeout, so a deploy or scale-in will SIGKILL a worker in the middle of a chunk instead of letting it finish or time out. Raise the container/supervisor stop grace above :timeout s.',
    'preflight_chunk_duration_headroom' => 'The declared expected chunk duration :chunk_seconds s leaves no headroom under the :timeout s job timeout: a chunk near p99 will trip the timeout. Raise the job timeout to at least :suggested s, or lower the chunk size.',
    'preflight_retry_after_below_timeout' => 'Connection [:connection] (:driver) has retry_after :retry_after s, which is not above the :timeout s job timeout, so the queue can release a chunk for another worker while the first copy is still running. Raise retry_after above :suggested s.',
    'preflight_sqs_visibility_margin_thin' => 'SQS queue [:queue] has VisibilityTimeout :visibility s against a :timeout s job timeout: the ordering is correct but the margin is only :margin s. Leave room for the worker to notice its own alarm and stop cleanly — raise VisibilityTimeout to at least :suggested s.',

    // Reasons a probe produced nothing. Any other failure (IAM denial, network,
    // an unknown connection) is reported with the throwable's own message.
    'preflight_probe_reason_sdk_missing' => 'the aws/aws-sdk-php package is not installed, so the queue cannot be inspected',
    'preflight_probe_reason_not_sqs' => 'the resolved queue connection is not an SQS queue instance',
    'preflight_probe_reason_unsupported_client' => 'the resolved SQS client does not expose GetQueueAttributes',
    'preflight_probe_reason_disabled' => 'the probe is disabled by SCOUT_IMPORT_PREFLIGHT_PROBE_QUEUE',
    'preflight_probe_reason_invalid' => 'the queue probe returned no usable attributes',

    // Report table: fact labels, value placeholders, provenance markers.
    'preflight_fact_connection' => 'Queue connection',
    'preflight_fact_driver' => 'Queue driver',
    'preflight_fact_queue' => 'Queue',
    'preflight_fact_queue_url' => 'Queue URL',
    'preflight_fact_job_timeout' => 'Job timeout (SCOUT_QUEUE_TIMEOUT)',
    'preflight_fact_effective_timeout' => 'Effective job timeout',
    'preflight_fact_worker_timeout' => 'Worker --timeout (declared)',
    'preflight_fact_visibility_timeout' => 'SQS VisibilityTimeout',
    'preflight_fact_retry_after' => 'retry_after',
    'preflight_fact_retry_after_sqs' => 'retry_after (inert: the SQS driver never reads it)',
    'preflight_fact_max_receive_count' => 'SQS maxReceiveCount',
    'preflight_fact_dead_letter' => 'SQS dead-letter target',
    'preflight_fact_tries' => 'Chunk tries',
    'preflight_fact_retry_until' => 'Retry deadline',
    'preflight_fact_failure_budget' => 'Failure budget',
    'preflight_fact_redispatch_limit' => 'Re-dispatch limit',
    'preflight_fact_dispatch_batch' => 'Dispatch batch',
    'preflight_fact_lock_ttl' => 'Import lease TTL',
    'preflight_fact_chunk' => 'Chunk size',
    'preflight_fact_shutdown_grace' => 'Shutdown grace (declared)',
    'preflight_fact_expected_chunk_seconds' => 'Expected chunk duration (declared)',
    'preflight_value_unknown' => 'unknown',
    'preflight_value_none' => 'none',
    'preflight_value_off' => 'off',
    'preflight_value_driver_default' => '(driver default)',
    'preflight_provenance_probed' => 'probed',
    'preflight_provenance_declared' => 'declared',
    'preflight_provenance_assumed' => 'assumed default',
    'preflight_provenance_unknown' => 'unknown',
    'preflight_source_job_timeout' => 'from SCOUT_QUEUE_TIMEOUT',
    'preflight_source_declared_worker_timeout' => 'declared worker --timeout, SCOUT_IMPORT_WORKER_TIMEOUT',
    'preflight_source_assumed_worker_timeout' => 'assumed queue:work --timeout default',
];
