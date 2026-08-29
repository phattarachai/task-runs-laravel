<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Phattarachai\TaskRunsLaravel\Concerns\InteractsWithTaskRun;

/**
 * A fine-grained consumer: manual markRunning / advance / reportProgress / markSuccess,
 * trait boilerplate.
 */
class TrackedTestJob implements ShouldQueue
{
    use InteractsWithTaskRun;
    use Queueable;

    public function handle(): void
    {
        if ($this->taskRunCancelledBeforeStart()) {
            return;
        }

        $this->taskRun->markRunning(3);

        foreach (range(1, 3) as $step) {
            $this->reportProgress("narrating step {$step}");
            $this->taskRun->advance(message: "step {$step}");
        }

        $this->taskRun->markSuccess('Done.');
    }
}
