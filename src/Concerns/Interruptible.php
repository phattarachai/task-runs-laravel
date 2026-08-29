<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Concerns;

use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\Support\RestartSignal;

/**
 * Opt-in add-on for multi-hour jobs: lets them hand the worker back and resume later instead of
 * being killed. Two things ask for that — a queue restart (deploys) and the job's own run budget
 * (a backlog outliving the worker timeout) — and the answer to both is identical: re-arm the
 * TaskRun as queued and re-dispatch, polled through the job's progress loop via {@see shouldYield()}.
 *
 * Re-dispatch, never `release()`: a `tries = 1` job that releases instantly exceeds its attempts.
 * The host job must declare `public int $timeout` — the budget is a fraction of it.
 */
trait Interruptible
{
    /**
     * Share of the worker timeout a job may spend before handing back. The remainder covers the
     * unwind — the current unit noticing, the re-dispatch, the TaskRun write.
     */
    private const float RUN_BUDGET_FRACTION = 0.8;

    protected ?string $restartBaseline = null;

    protected ?float $watchStartedAt = null;

    /**
     * Capture the restart baseline and start the budget clock. Call at the top of `handle()`.
     */
    protected function watchForInterruption(): void
    {
        $this->restartBaseline = RestartSignal::current();
        $this->watchStartedAt = microtime(as_float: true);
    }

    /**
     * Whether to stop now and resume later: a restart was signalled, or the budget is spent.
     */
    protected function shouldYield(): bool
    {
        if ($this->restartRequested()) {
            return true;
        }

        return $this->budgetExhausted();
    }

    protected function restartRequested(): bool
    {
        return RestartSignal::current() !== $this->restartBaseline;
    }

    protected function budgetExhausted(): bool
    {
        if ($this->watchStartedAt === null) {
            return false;
        }

        return (microtime(as_float: true) - $this->watchStartedAt) >= $this->timeout * self::RUN_BUDGET_FRACTION;
    }

    /**
     * Re-arm the run as queued and re-dispatch it so a fresh worker resumes the remaining work.
     * The continuation stays on the connection *and* queue the job was dispatched to — the wrong
     * one strands it somewhere no supervisor is watching. The re-dispatch is the only thing that
     * keeps the run alive; dying between the two calls is what the dispatcher's orphan guard
     * exists to clean up.
     */
    protected function requeueForYield(TaskRun $run): void
    {
        $run->markRequeued($this->restartRequested()
            ? 'Paused for a queue restart; resuming shortly.'
            : 'Paused to stay inside the worker timeout; resuming shortly.');

        static::dispatch($run)
            ->onConnection($this->connection)
            ->onQueue($this->queue);
    }
}
