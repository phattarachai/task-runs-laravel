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
 * Broadcast on every {@see TaskRun} lifecycle write — only fired at all when
 * `task-runs.broadcast.enabled` is true, so a host without a socket stays poll-only.
 * The event name is pinned to `TaskRunStatusChanged` so clients listen with a stable name
 * regardless of the package namespace.
 */
class TaskRunStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(public array $state) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channel = (string) config('task-runs.broadcast.channel', 'task-runs');

        return [
            config('task-runs.broadcast.private', true) === true
                ? new PrivateChannel($channel)
                : new Channel($channel),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TaskRunStatusChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->state;
    }
}
