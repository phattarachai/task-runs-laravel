<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Concerns;

use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Throwable;

/**
 * The per-run job boilerplate in one place: the promoted `$taskRun` constructor the dispatcher
 * relies on, the `failed()` hook that marks the run failed, and the queued-cancel guard for the
 * top of `handle()`. Progress stays manual — call `$this->taskRun->markRunning()` / `advance()`
 * where the work actually happens.
 */
trait InteractsWithTaskRun
{
    public function __construct(public TaskRun $taskRun) {}

    public function failed(?Throwable $exception): void
    {
        $this->taskRun->markFailed($exception?->getMessage() ?? 'Task failed.');
    }

    /**
     * Whether to bail before doing any work — cancelled while still queued. Marks the run
     * cancelled, so `handle()` can simply return.
     */
    protected function taskRunCancelledBeforeStart(): bool
    {
        if (! $this->taskRun->isCancelled()) {
            return false;
        }

        if ($this->taskRun->status !== TaskRun::CANCELLED) {
            $this->taskRun->markCancelled('Cancelled before it started.');
        }

        return true;
    }
}
