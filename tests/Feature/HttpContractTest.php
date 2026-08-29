<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\TrackedTestJob;

test('the poll endpoint returns the active + history + horizon snapshot', function (): void {
    TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING, 'total' => 10, 'processed' => 4]);
    TaskRun::query()->create(['type' => 'coarse', 'status' => TaskRun::SUCCESS]);

    $this->getJson('/tasks/poll')
        ->assertOk()
        ->assertJsonCount(1, 'active')
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('active.0.type', 'demo')
        ->assertJsonPath('active.0.processed', 4)
        ->assertJsonStructure(['active', 'history', 'horizon' => ['status', 'masters']]);
});

test('history respects the configured limit, newest first', function (): void {
    config()->set('task-runs.history_limit', 2);

    foreach (range(1, 4) as $i) {
        TaskRun::query()->create(['type' => "t{$i}", 'status' => TaskRun::SUCCESS]);
    }

    $this->getJson('/tasks/poll')
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.type', 't4');
});

test('the run endpoint dispatches a whitelisted type and returns its snapshot', function (): void {
    Queue::fake();

    $this->postJson('/tasks/run/demo')
        ->assertOk()
        ->assertJsonPath('type', 'demo')
        ->assertJsonPath('status', TaskRun::QUEUED);

    Queue::assertPushed(TrackedTestJob::class, 1);
    expect(TaskRun::query()->sole()->dispatched_by)->toBe('ui');
});

test('an unknown run type 404s', function (): void {
    $this->postJson('/tasks/run/bogus')->assertNotFound();
});

test('a runnable whitelist restricts the run endpoint below the jobs map', function (): void {
    Queue::fake();
    config()->set('task-runs.runnable', ['coarse']);

    $this->postJson('/tasks/run/demo')->assertNotFound();
    $this->postJson('/tasks/run/coarse')->assertOk();
});

test('cancelling a running run flips the cooperative flag only', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    $this->postJson("/tasks/{$run->id}/cancel")
        ->assertOk()
        ->assertJsonPath('cancel_requested', true)
        ->assertJsonPath('status', TaskRun::RUNNING);
});

test('cancelling a queued run marks it cancelled outright', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::QUEUED]);

    $this->postJson("/tasks/{$run->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', TaskRun::CANCELLED);

    expect($run->refresh()->message)->toBe('Cancelled before it started.');
});

test('the index page renders the configured Inertia component with the client config', function (): void {
    TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    $this->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('TaskRuns')
            ->has('active', 1)
            ->has('history', 0)
            ->has('horizon.status')
            ->where('config.runnable', ['demo', 'coarse'])
            ->where('config.broadcast.enabled', false)
            ->has('config.endpoints.poll')
            ->has('config.endpoints.run')
            ->has('config.endpoints.cancel'));
});

test('the route prefix is configurable', function (): void {
    expect(route('task-runs.poll'))->toEndWith('/tasks/poll');
});
