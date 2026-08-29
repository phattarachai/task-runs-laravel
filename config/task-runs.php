<?php

declare(strict_types=1);

use Phattarachai\TaskRunsLaravel\Models\TaskRun;

return [
    // Swap in an app-level subclass to add columns, relations, or extra lifecycle writers.
    'model' => TaskRun::class,

    'table' => env('TASK_RUNS_TABLE', 'task_runs'),

    // Task type => job class. The job receives the TaskRun as its only constructor argument.
    'jobs' => [
        // 'scan' => \App\Jobs\ScanJob::class,
    ],

    // Task type => queue name. Types not listed ride `default_queue`.
    'queues' => [],

    // null = the connection's default queue.
    'default_queue' => null,

    // Queue name => connection name, with '*' as the fallback. null / missing = the default
    // connection. For anything richer, call TaskDispatcher::resolveConnectionUsing().
    'connections' => [],

    // How long an active run may sit untouched before an empty queue means its job is gone
    // (the dispatcher then fails + supersedes it). null disables the orphan guard.
    'orphan_grace_seconds' => 60,

    // Finished runs older than this are removed by `model:prune`. null = keep forever.
    'retention_days' => env('TASK_RUNS_RETENTION_DAYS'),

    'history_limit' => (int) env('TASK_RUNS_HISTORY_LIMIT', 30),

    // Task types the HTTP run endpoint may dispatch. null = every type in `jobs`; [] = none.
    'runnable' => null,

    'broadcast' => [
        'enabled' => (bool) env('TASK_RUNS_BROADCAST', false),
        'channel' => env('TASK_RUNS_BROADCAST_CHANNEL', 'task-runs'),
        'private' => true,
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => env('TASK_RUNS_PATH', 'tasks'),
        'middleware' => ['web', 'auth'],
    ],

    'ui' => [
        // The Inertia index route registers only when this is true AND inertia-laravel is installed.
        'enabled' => true,
        'page' => 'TaskRuns',
        'title' => 'Background Tasks',
    ],
];
