<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Phattarachai\TaskRunsLaravel\Http\Resources\TaskRunResource;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\Support\HorizonStatus;
use Phattarachai\TaskRunsLaravel\TaskDispatcher;
use Phattarachai\TaskRunsLaravel\TaskRuns;

/**
 * The tasks screen contract: `index` renders the (optional) Inertia page, `poll` is the JSON
 * snapshot for the polling loop / broadcast fallback, `run` fires a whitelisted type, `cancel`
 * requests a cooperative stop.
 */
class TaskRunController
{
    public function index(TaskDispatcher $dispatcher): Response
    {
        return Inertia::render((string) config('task-runs.ui.page', 'TaskRuns'), [
            ...$this->snapshot(),
            'config' => $this->clientConfig($dispatcher),
        ]);
    }

    public function poll(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    public function run(string $type, TaskDispatcher $dispatcher): JsonResponse
    {
        abort_unless(in_array($type, $this->runnable($dispatcher), strict: true), 404, "Task type [{$type}] cannot be run here.");

        $run = $dispatcher->dispatch($type, dispatchedBy: 'ui');

        return response()->json(new TaskRunResource($run)->resolve());
    }

    /**
     * Request cooperative cancellation — the running job checks the flag between chunks. A
     * still-queued run is cancelled outright (its job no-ops on a cancelled run).
     */
    public function cancel(int $taskRun): JsonResponse
    {
        /** @var TaskRun $run */
        $run = TaskRuns::query()->findOrFail($taskRun);

        $run->requestCancel();

        if ($run->status === TaskRun::QUEUED) {
            $run->markCancelled('Cancelled before it started.');
        }

        return response()->json(new TaskRunResource($run->refresh())->resolve());
    }

    /**
     * @return array{active: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>, horizon: array{status: string, masters: int}}
     */
    private function snapshot(): array
    {
        $active = TaskRuns::query()->active()->latest('id')->get();

        $history = TaskRuns::query()
            ->whereNotIn('status', [TaskRun::QUEUED, TaskRun::RUNNING])
            ->latest('id')
            ->limit((int) config('task-runs.history_limit', 30))
            ->get();

        return [
            'active' => TaskRunResource::collection($active)->resolve(),
            'history' => TaskRunResource::collection($history)->resolve(),
            'horizon' => HorizonStatus::current(),
        ];
    }

    /**
     * @return list<string>
     */
    private function runnable(TaskDispatcher $dispatcher): array
    {
        /** @var list<string>|null $runnable */
        $runnable = config('task-runs.runnable');

        return $runnable ?? $dispatcher->types();
    }

    /**
     * Everything the published page needs — URLs as plain strings (no route helpers client-side).
     *
     * @return array<string, mixed>
     */
    private function clientConfig(TaskDispatcher $dispatcher): array
    {
        return [
            'title' => (string) config('task-runs.ui.title', 'Background Tasks'),
            'runnable' => $this->runnable($dispatcher),
            'broadcast' => [
                'enabled' => config('task-runs.broadcast.enabled') === true,
                'channel' => (string) config('task-runs.broadcast.channel', 'task-runs'),
                'private' => config('task-runs.broadcast.private', true) === true,
            ],
            'endpoints' => [
                'poll' => route('task-runs.poll'),
                'run' => route('task-runs.run', ['type' => '__TYPE__']),
                'cancel' => route('task-runs.cancel', ['taskRun' => '__ID__']),
            ],
        ];
    }
}
