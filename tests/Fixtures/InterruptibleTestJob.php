<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Phattarachai\TaskRunsLaravel\Concerns\InteractsWithTaskRun;
use Phattarachai\TaskRunsLaravel\Concerns\Interruptible;

/**
 * A long job that yields cooperatively: one unit of work per shouldYield() poll.
 * `$onStep` lets a test flip the restart signal mid-run, after the baseline is captured.
 */
class InterruptibleTestJob implements ShouldQueue
{
    use InteractsWithTaskRun;
    use Interruptible;
    use Queueable;

    public static ?Closure $onStep = null;

    public int $tries = 1;

    public int $timeout = 3600;

    public function handle(): void
    {
        $this->taskRun->markRunning(10);
        $this->watchForInterruption();

        foreach (range(1, 10) as $step) {
            (self::$onStep ?? fn (int $step) => null)($step);

            if ($this->shouldYield()) {
                $this->requeueForYield($this->taskRun);

                return;
            }

            $this->taskRun->advance();
        }

        $this->taskRun->markSuccess();
    }
}
