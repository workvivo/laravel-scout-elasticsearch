<?php

return [
    'start' => 'Importing [:searchable]',
    'done' => 'All [:searchable] records have been imported.',
    'done.queue' => 'Import job dispatched to the queue.',
    'already_running' => 'An import for [:searchable] is already running. Skipping.',
    'parallel_requires_queue' => 'The --parallel option requires a queue. Configure scout.queue before using it.',
];
