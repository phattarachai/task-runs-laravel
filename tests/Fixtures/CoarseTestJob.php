<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Phattarachai\TaskRunsLaravel\Concerns\InteractsWithTaskRun;
use Phattarachai\TaskRunsLaravel\Jobs\Middleware\TrackedJob;

/**
 * A coarse consumer: no progress, tracking delegated entirely to the TrackedJob middleware.
 * `$work` lets a test inject the handle body (a throw, a self-cancel, …).
 */
class CoarseTestJob implements ShouldQueue
{
    use InteractsWithTaskRun;
    use Queueable;

    public static ?Closure $work = null;

    public function handle(): void
    {
        (self::$work ?? fn () => null)($this->taskRun);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new TrackedJob];
    }
}
