<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;

/**
 * The single entry point for background tasks — UI, CLI and scheduler all route through it.
 * Maps a task type to its job via config, refuses a second concurrent run of the same
 * type + subject, recovers runs whose job vanished, and records the queued {@see TaskRun}
 * before dispatching.
 */
class TaskDispatcher
{
    private static ?Closure $connectionResolver = null;

    /**
     * Override queue → connection resolution with app code when the `task-runs.connections`
     * config map isn't expressive enough. Pass null to restore the config-driven default.
     *
     * @param  (Closure(?string): ?string)|null  $resolver
     */
    public static function resolveConnectionUsing(?Closure $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    /**
     * Queue a task, or hand back the run already in flight for this type + subject. The overlap
     * guard returns the existing active run rather than throwing, so a double-click is a no-op —
     * unless that run has lost its job, in which case it is failed and superseded.
     *
     * @param  array<string, mixed>  $options
     */
    public function dispatch(string $type, ?Model $subject = null, array $options = [], string $dispatchedBy = 'ui'): TaskRun
    {
        $job = $this->jobClass($type);
        $queue = $this->queueFor($type);

        /** @var TaskRun|null $active */
        $active = TaskRuns::query()->active()
            ->where('subject_type', $subject?->getMorphClass())
            ->where('subject_id', $subject?->getKey())
            ->where('type', $type)
            ->first();

        if ($active !== null && ! $this->isOrphaned($active, $queue)) {
            return $active;
        }

        $active?->markFailed(sprintf(
            'Abandoned: nothing left on the %s queue to finish it. Re-dispatched.',
            $queue ?? 'default',
        ));

        /** @var TaskRun $run */
        $run = TaskRuns::query()->create([
            'type' => $type,
            'status' => TaskRun::QUEUED,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'options' => $options === [] ? null : $options,
            'dispatched_by' => $dispatchedBy,
        ]);

        $job::dispatch($run)
            ->onConnection($this->connectionFor($queue))
            ->onQueue($queue);

        return $run;
    }

    /**
     * The task types the dispatcher knows, in config order.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(strval(...), array_keys((array) config('task-runs.jobs', [])));
    }

    /**
     * @return class-string
     */
    private function jobClass(string $type): string
    {
        /** @var class-string */
        return config('task-runs.jobs.'.$type)
            ?? throw new InvalidArgumentException("Unknown task type [{$type}].");
    }

    private function queueFor(string $type): ?string
    {
        $queue = config('task-runs.queues.'.$type) ?? config('task-runs.default_queue');

        return $queue === null ? null : (string) $queue;
    }

    private function connectionFor(?string $queue): ?string
    {
        if (self::$connectionResolver !== null) {
            return (self::$connectionResolver)($queue);
        }

        $map = (array) config('task-runs.connections', []);

        $connection = $map[$queue] ?? $map['*'] ?? null;

        return $connection === null ? null : (string) $connection;
    }

    /**
     * Whether an "active" run has lost the job that was supposed to advance it, and so will never
     * finish on its own — a worker killed mid-requeue or a flushed queue store leaves exactly this.
     * The test is exact rather than a timeout on the run itself: an empty queue means no job of
     * any kind is left to advance anything on it, while a run legitimately waiting behind a long
     * job sits on a non-empty queue and is left alone.
     */
    private function isOrphaned(TaskRun $run, ?string $queue): bool
    {
        $grace = config('task-runs.orphan_grace_seconds', 60);

        if ($grace === null) {
            return false;
        }

        $idleSince = $run->updated_at ?? $run->created_at;

        if ($idleSince === null || $idleSince->gt(now()->subSeconds((int) $grace))) {
            return false;
        }

        return Queue::connection($this->connectionFor($queue))->size($queue) === 0;
    }
}
