<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Phattarachai\TaskRunsLaravel\Events\TaskRunStatusChanged;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;

test('lifecycle writes broadcast the snapshot when broadcasting is enabled', function (): void {
    config()->set('task-runs.broadcast.enabled', true);
    Event::fake([TaskRunStatusChanged::class]);

    $run = TaskRun::query()->create(['type' => 'demo']);
    $run->markRunning(5);
    $run->advance();
    $run->markSuccess('done');

    Event::assertDispatchedTimes(TaskRunStatusChanged::class, 3);
    Event::assertDispatched(TaskRunStatusChanged::class, fn (TaskRunStatusChanged $event): bool => $event->state['type'] === 'demo' && $event->state['status'] === TaskRun::SUCCESS);
});

test('nothing is fired when broadcasting is disabled — poll-only hosts stay quiet', function (): void {
    Event::fake([TaskRunStatusChanged::class]);

    $run = TaskRun::query()->create(['type' => 'demo']);
    $run->markRunning();
    $run->markSuccess();

    Event::assertNotDispatched(TaskRunStatusChanged::class);
});

test('the event rides the configured channel under a stable name', function (): void {
    config()->set('task-runs.broadcast.channel', 'ops');

    $event = new TaskRunStatusChanged(['id' => 1]);

    expect($event->broadcastAs())->toBe('TaskRunStatusChanged');
    expect($event->broadcastWith())->toBe(['id' => 1]);
    expect($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class);
    expect((string) $event->broadcastOn()[0]->name)->toBe('private-ops');
});
