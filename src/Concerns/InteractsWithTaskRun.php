<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Concerns;

use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Throwable;

/**
 * The per-run job boilerplate in one place: the promoted `$taskRun` constructor the dispatcher
 * relies on, the `failed()` hook that marks the run failed, and the queued-cancel guard for the
 * top of `handle()`. Progress stays manual — call `$this->taskRun->markRunning()` / `advance()` /
 * `reportProgress()` where the work actually happens.
 */
trait InteractsWithTaskRun
{
    public function __construct(public TaskRun $taskRun) {}

    public function failed(?Throwable $exception): void
    {
        $this->taskRun->markFailed($exception?->getMessage() ?? 'Task failed.');
    }

    /**
     * Append one short, human-readable line to the run's trail — what the job is doing
     * right now, in the language the UI shows. Unlike `advance()` this moves no counter,
     * so narration and progress stay independent.
     */
    protected function reportProgress(string $line): void
    {
        $this->taskRun->reportProgress($line);
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
