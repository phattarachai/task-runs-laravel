<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Jobs\Middleware;

use Closure;
use Phattarachai\TaskRunsLaravel\Concerns\InteractsWithTaskRun;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Throwable;

/**
 * Coarse start/finish tracking for jobs with no per-chunk progress: marks the run running before
 * `handle()`, success after it, failed on a throw — and skips the work entirely when the run was
 * cancelled while queued. Add `return [new TrackedJob];` from the job's `middleware()`; the job
 * only needs a public `$taskRun` property (the {@see InteractsWithTaskRun}
 * constructor provides one). A job that needs progress calls `advance()` itself instead.
 */
class TrackedJob
{
    public function handle(object $job, Closure $next): void
    {
        $run = $this->runOf($job);

        if ($run === null) {
            $next($job);

            return;
        }

        if ($run->isCancelled()) {
            $this->cancelBeforeStart($run);

            return;
        }

        $run->markRunning();

        try {
            $next($job);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }

        $this->finish($run);
    }

    private function runOf(object $job): ?TaskRun
    {
        $run = $job->taskRun ?? null;

        return $run instanceof TaskRun ? $run : null;
    }

    private function cancelBeforeStart(TaskRun $run): void
    {
        if ($run->status === TaskRun::CANCELLED) {
            return;
        }

        $run->markCancelled('Cancelled before it started.');
    }

    /**
     * Success only when `handle()` left the run untouched at `running` — a job that marked
     * itself cancelled, failed, or requeued keeps that verdict.
     */
    private function finish(TaskRun $run): void
    {
        if ($run->refresh()->status !== TaskRun::RUNNING) {
            return;
        }

        $run->markSuccess();
    }
}
