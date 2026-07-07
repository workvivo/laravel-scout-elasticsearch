<?php

return [
    'start' => 'Importing [:searchable]',
    'done' => 'All [:searchable] records have been imported.',
    'done.queue' => 'Import job dispatched to the queue.',
    'already_running' => 'An import for [:searchable] is already running. Skipping.',
    'parallel_requires_async_queue' => 'The --parallel option needs an asynchronous queue, but connection [:connection] uses the sync driver. Pass --connection, set your default queue, or use --force to run inline.',
];
