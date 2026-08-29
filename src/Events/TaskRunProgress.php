<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;

/**
 * One newly appended {@see TaskRun::reportProgress()} line — only fired at all when
 * `task-runs.broadcast.enabled` is true.
 *
 * Unlike {@see TaskRunStatusChanged}, which rides the one shared channel because the
 * tasks screen watches every run at once, this rides a channel **per run**: a feed
 * drawer open on one run has no business receiving another run's narration. The event
 * name is pinned so clients listen with a stable name regardless of namespace.
 */
class TaskRunProgress implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{at: string, line: string}  $entry
     */
    public function __construct(
        public int|string $taskRunId,
        public string $status,
        public array $entry,
    ) {}

    /**
     * The per-run channel name — `{task-runs.broadcast.channel}.{id}`. Pass a placeholder
     * to hand a client the pattern it should fill in.
     */
    public static function channelName(int|string $taskRunId): string
    {
        return config('task-runs.broadcast.channel', 'task-runs').'.'.$taskRunId;
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channel = self::channelName($this->taskRunId);

        return [
            config('task-runs.broadcast.private', true) === true
                ? new PrivateChannel($channel)
                : new Channel($channel),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TaskRunProgress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->taskRunId,
            'status' => $this->status,
            'entry' => $this->entry,
        ];
    }
}
