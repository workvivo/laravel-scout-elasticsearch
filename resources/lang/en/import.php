<?php

return [
    'start' => 'Importing [:searchable]',
    'done' => 'All [:searchable] records have been imported.',
    'done_summary' => '[:searchable] imported: :indexed documents in :elapsed.',
    'done.queue' => 'Import job dispatched to the queue.',
    'already_running' => 'An import for [:searchable] is already running. Skipping.',
    'parallel_requires_async_queue' => 'The --parallel option needs an asynchronous queue, but connection [:connection] uses the sync driver. Pass --connection, set your default queue, or use --force to run inline.',
    'invalid_chunk' => 'The --chunk option must be a positive integer.',
    'wait_needs_parallel' => 'The --wait option only applies to --parallel imports and was ignored.',
    'indexing' => 'Indexing [:searchable]',
    'wait_preparing' => 'Preparing [:searchable] (creating index and planning chunks)…',
    'wait_no_batch' => 'Dispatched [:searchable], but no worker picked it up within the wait timeout. It is still queued.',
    'wait_summary' => '[:searchable] imported: :indexed documents across :chunks chunks in :elapsed.',
    'wait_summary_empty' => '[:searchable] had no records to import.',
    'wait_failed' => '[:searchable] import failed: :failed of :chunks chunks failed after :elapsed. The previous index is still serving.',
];
