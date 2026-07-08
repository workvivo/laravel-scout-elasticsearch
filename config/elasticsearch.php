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
        // Seconds `scout:import --parallel --wait` polls for the batch to be
        // created before giving up and leaving the work queued (e.g. when no
        // worker is running to pick it up).
        'wait_timeout' => (int) env('SCOUT_IMPORT_WAIT_TIMEOUT', 120),

        // Opt-in retry for the fanned-out chunk jobs of a --parallel import.
        // Defaults reproduce today's behaviour: tries=1 means a chunk that
        // throws fails immediately. Raising tries lets a transient failure —
        // most importantly a MySQL 1205 "Lock wait timeout" on the job_batches
        // bookkeeping row under heavy parallel fan-out — be retried with
        // exponential, jittered backoff instead of cancelling the whole model's
        // batch. Safe because the chunk re-index is idempotent (stable document
        // ids overwrite). Applies to chunk jobs only; the prepare stages never
        // retry. IMPORTANT: keep the queue connection's retry_after (or SQS
        // visibility timeout) greater than `queue.timeout` above, or the queue
        // will re-run a still-running chunk and trip MaxAttemptsExceeded.
        'batch' => [
            'tries' => (int) env('SCOUT_IMPORT_BATCH_TRIES', 1),
            'backoff_base' => (int) env('SCOUT_IMPORT_BATCH_BACKOFF_BASE', 5),
            'backoff_cap' => (int) env('SCOUT_IMPORT_BATCH_BACKOFF_CAP', 120),
            // Optional wall-clock ceiling per chunk, in seconds (0 = bounded by
            // tries only).
            'retry_until' => (int) env('SCOUT_IMPORT_BATCH_RETRY_UNTIL', 0),
        ],

        // Per-model chunk size for imports, keyed by the model's searchableAs().
        // Fewer, larger chunks = fewer batch completions = less job_batches lock
        // contention on large tables. Precedence: the --chunk option, then the
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
