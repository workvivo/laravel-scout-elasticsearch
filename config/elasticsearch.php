<?php

return [
    'host' => env('ELASTICSEARCH_HOST'),
    'user' => env('ELASTICSEARCH_USER'),
    'password' => env('ELASTICSEARCH_PASSWORD'),
    'cloud_id' => env('ELASTICSEARCH_CLOUD_ID'),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'queue' => [
        'timeout' => env('SCOUT_QUEUE_TIMEOUT'),
    ],
    'import' => [
        // Per-model import lease TTL, in seconds. This is an INACTIVITY timeout,
        // not a cap on total import time: a running import renews the lease as
        // each chunk completes, so imports that take many hours on large tables
        // stay locked the whole time, while a crashed run self-heals after one
        // idle window. Set it comfortably above the time a single chunk takes to
        // index. Requires a cache store with atomic add (redis, memcached,
        // database, dynamodb) — the file driver is unsuitable.
        'lock_ttl' => (int) env('SCOUT_IMPORT_LOCK_TTL', 3600),
        // Seconds `scout:import --parallel --wait` polls for the run record to
        // be created before giving up and leaving the work queued (e.g. when no
        // worker is running to pick it up).
        'wait_timeout' => (int) env('SCOUT_IMPORT_WAIT_TIMEOUT', 120),

        // Retry for the fanned-out chunk jobs of a --parallel import.
        // Defaults reproduce today's behaviour: tries=1 means a chunk that
        // throws fails immediately. Raising tries lets a transient failure be
        // retried with exponential, jittered backoff. Safe because the chunk
        // re-index is idempotent (stable document ids overwrite). Applies to
        // chunk jobs only; the prepare stages never retry.
        //
        // IMPORTANT — the redelivery window must exceed the time a chunk takes,
        // or the queue makes a duplicate available while the first copy is still
        // running. On SQS this is NOT `retry_after`: the SQS connector never
        // reads that config value, so the only lever is the queue's AWS-side
        // `VisibilityTimeout` attribute. On the redis/database drivers it is the
        // connection's `retry_after`.
        //
        // What the redelivery window must exceed is the worker's own timeout,
        // which is NOT necessarily `queue.timeout` above: that value is null
        // unless SCOUT_QUEUE_TIMEOUT is set, and when it is null the real bound
        // is the worker's `--timeout` (default 60s). Whichever applies, the
        // job's own alarm should fire FIRST — that path fails and deletes the
        // message cleanly, instead of leaving a live job behind and letting the
        // broker manufacture a phantom second delivery.
        //
        // On SQS also keep `maxReceiveCount` on the queue's redrive policy
        // greater than `tries`, or the dead-letter queue swallows a message the
        // worker still intended to retry.
        'retry' => [
            'tries' => (int) env('SCOUT_IMPORT_RETRY_TRIES', 1),
            'backoff_base' => (int) env('SCOUT_IMPORT_RETRY_BACKOFF_BASE', 5),
            'backoff_cap' => (int) env('SCOUT_IMPORT_RETRY_BACKOFF_CAP', 120),
            // Optional retry deadline, in seconds (0 = off, and off is strongly
            // recommended). Two sharp edges:
            //   1. It is NOT a per-chunk wall-clock ceiling. Laravel evaluates
            //      it ONCE at push time and freezes an absolute timestamp into
            //      the payload, so on a large fan-out every chunk still sitting
            //      in the queue when the deadline passes fails on its FIRST
            //      receive, having never run.
            //   2. It does NOT compose with `tries`. The worker only compares
            //      attempts against maxTries when no retryUntil is set, so a
            //      non-zero value SILENTLY DISABLES `tries` and gives a failing
            //      chunk unbounded attempts until the deadline.
            'retry_until' => (int) env('SCOUT_IMPORT_RETRY_UNTIL', 0),
        ],
        'rollback_delay' => (int) env('SCOUT_IMPORT_ROLLBACK_DELAY', 5),

        // How many chunks may fail before the whole run is marked failed and
        // rolled back. A "failure" here means a genuine one: a chunk that threw
        // with no execution lease still live. Duplicate deliveries and workers
        // killed mid-flight are re-dispatched instead of counted (the re-index
        // is idempotent, so re-running a chunk is always safe). The same chunk
        // failing repeatedly only ever consumes one slot.
        // 1 reproduces today's behaviour exactly: one genuine failure rolls back.
        'failure_budget' => (int) env('SCOUT_IMPORT_FAILURE_BUDGET', 1),

        // Seconds added to the job timeout to derive a chunk's execution-lease
        // TTL. The lease is what tells a failing delivery "another copy of this
        // chunk is still running (or was hard-killed mid-run), re-dispatch me
        // rather than fail the run". The pad must cover the gap between the
        // worker's alarm and the point the process actually stops, so a lease
        // never expires under a chunk that is still writing.
        'chunk_lease_pad' => (int) env('SCOUT_IMPORT_CHUNK_LEASE_PAD', 120),

        // How many times a single chunk may be re-dispatched because a live
        // lease made its failure ambiguous. Once exhausted the chunk is treated
        // as a genuine failure, so a pathological chunk cannot loop forever.
        'redispatch_limit' => (int) env('SCOUT_IMPORT_REDISPATCH_LIMIT', 3),

        // Chunks dispatched per fan-out hop. A plan larger than this is enqueued
        // across several self-re-enqueuing hops instead of one long-running job,
        // so fanning out a very large table cannot outlive its own visibility
        // window. Plans at or below this size are dispatched in a single hop,
        // exactly as before.
        'dispatch_batch' => (int) env('SCOUT_IMPORT_DISPATCH_BATCH', 1000),

        // Seconds between passes of the finalize reaper, a self-rescheduling job
        // that finalizes a complete run whose last chunk failed to hand off, and
        // re-dispatches chunks that vanished with no lease live. 0 disables it,
        // which is the default: completion then depends, as today, on the chunk
        // that observes the run become complete.
        'reaper_interval' => (int) env('SCOUT_IMPORT_REAPER_INTERVAL', 0),

        // How many DISTINCT profiling findings a `--profile-samples` run may publish into
        // its run record, where `scout:import --parallel --wait` can read them
        // back and print them. Findings are aggregated by code, so this is not a
        // cap on profiled chunks: a run that trips the same n+1 on all 100k of
        // them stores one row carrying the occurrence count and the worst
        // example. The cap only bounds how many different kinds of problem are
        // reported, and a code already being tracked keeps counting after the
        // cap is reached. 0 disables publication entirely — no writes at all.
        'profile_findings' => (int) env('SCOUT_IMPORT_PROFILE_FINDINGS', 20),

        // Pre-flight for the queue timing invariant a --parallel import depends
        // on:
        //
        //   p99 chunk << effective job timeout < redelivery window < shutdown grace
        //
        // The job's own alarm has to win the race against the broker's
        // redelivery: the timeout path fails and deletes the message cleanly,
        // whereas a redelivery that overtakes a still-running chunk manufactures
        // a phantom second delivery. On SQS that is fatal rather than merely
        // wasteful, because `attempts()` IS `ApproximateReceiveCount`, so the
        // second delivery arrives already looking exhausted and the worker fails
        // it before the job body ever runs.
        //
        // The check aborts ONLY on positive, probed evidence (a VisibilityTimeout
        // read from the queue that sits at or below the governing job timeout).
        // A failed probe, a non-SQS driver or an undeclared value is a warning:
        // nothing is proven, so nothing is blocked. --force skips the abort.
        'preflight' => [
            // Master switch for the --parallel queue-timing check.
            'enabled' => (bool) env('SCOUT_IMPORT_PREFLIGHT', true),

            // Values this process CANNOT discover, in seconds; 0 = undeclared.
            //
            // Declare them and the check can reason about them; leave them at 0
            // and it can only say "unknown". None of the three is obtainable
            // from inside PHP:
            //   - worker_timeout is the `queue:work --timeout` of a different
            //     process, possibly on another host. It is what actually bounds
            //     a chunk whenever SCOUT_QUEUE_TIMEOUT is unset, and the
            //     framework's default is 60.
            //   - shutdown_grace is the container/supervisor stop grace, i.e.
            //     how long a worker has between SIGTERM and SIGKILL on a deploy.
            //   - expected_chunk_seconds is the p99 duration of one chunk on
            //     YOUR data. Measure it with `scout:import --probe` before you
            //     commit to a run, or with `--profile-samples` during one, then
            //     declare it here.
            'worker_timeout' => (int) env('SCOUT_IMPORT_WORKER_TIMEOUT', 0),
            'shutdown_grace' => (int) env('SCOUT_IMPORT_SHUTDOWN_GRACE', 0),
            'expected_chunk_seconds' => (int) env('SCOUT_IMPORT_EXPECTED_CHUNK_SECONDS', 0),

            // Set false to skip the read-only SQS GetQueueAttributes call — e.g.
            // when the app's IAM role lacks sqs:GetQueueAttributes. Skipping it
            // costs the only fact the check can prove, so the timing ordering
            // then goes unverified.
            'probe_queue' => (bool) env('SCOUT_IMPORT_PREFLIGHT_PROBE_QUEUE', true),
        ],

        // Per-model chunk size for imports, keyed by the model's searchableAs().
        // Fewer, larger chunks = fewer queued jobs. Precedence: the --chunk option, then the
        // per-model entry here, then 'default', then scout.chunk.searchable (500).
        // Absent keys change nothing. Example:
        //   'chunk' => ['default' => null, 'products' => 2000, 'orders' => 5000],
        'chunk' => [
            'default' => null,
        ],
    ],
    'indices' => [
        'mappings' => [
            'default' => [
                'properties' => [
                    'id' => [
                        'type' => 'keyword',
                    ],
                ],
            ],
        ],
        'settings' => [
            'default' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
            ],
        ],
    ],
];
