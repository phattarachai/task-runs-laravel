<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Support;

use Illuminate\Support\Facades\Cache;
use Phattarachai\TaskRunsLaravel\Concerns\Interruptible;

/**
 * Reads the queue restart signal that `horizon:terminate` / `queue:restart` write. A normal
 * worker reacts to this key *between* jobs, but a job running for hours inside a single
 * `handle()` never gets there, so it polls the key itself via {@see Interruptible} and
 * cooperatively re-queues when it changes.
 */
class RestartSignal
{
    /**
     * The current restart-signal value, or null if one has never been set.
     */
    public static function current(): ?string
    {
        $value = Cache::get('illuminate:queue:restart');

        return $value === null ? null : (string) $value;
    }
}
