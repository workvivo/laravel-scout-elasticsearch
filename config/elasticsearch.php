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
