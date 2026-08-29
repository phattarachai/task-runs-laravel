<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\TaskDispatcher;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\SubjectFixture;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\TrackedTestJob;

afterEach(fn () => TaskDispatcher::resolveConnectionUsing(null));

function dispatcher(): TaskDispatcher
{
    return app(TaskDispatcher::class);
}

test('an unknown task type throws', function (): void {
    dispatcher()->dispatch('bogus');
})->throws(InvalidArgumentException::class, 'Unknown task type [bogus].');

test('dispatch records a queued run and pushes the mapped job', function (): void {
    Queue::fake();

    $run = dispatcher()->dispatch('demo', options: ['all' => true], dispatchedBy: 'schedule');

    expect($run->status)->toBe(TaskRun::QUEUED);
    expect($run->options)->toBe(['all' => true]);
    expect($run->dispatched_by)->toBe('schedule');

    Queue::assertPushed(TrackedTestJob::class, fn (TrackedTestJob $job): bool => $job->taskRun->is($run));
});

test('empty options are stored as null', function (): void {
    Queue::fake();

    expect(dispatcher()->dispatch('demo')->options)->toBeNull();
});

test('the overlap guard reuses an active run instead of dispatching a duplicate', function (): void {
    Queue::fake();

    $first = dispatcher()->dispatch('demo');
    $second = dispatcher()->dispatch('demo', options: ['all' => true]);

    expect($second->id)->toBe($first->id);
    expect(TaskRun::query()->count())->toBe(1);
    Queue::assertPushed(TrackedTestJob::class, 1);
});

test('overlap is tracked per subject independently', function (): void {
    Queue::fake();

    $subjectA = new SubjectFixture()->forceFill(['id' => 1]);
    $subjectB = new SubjectFixture()->forceFill(['id' => 2]);

    $a = dispatcher()->dispatch('demo', $subjectA);
    $b = dispatcher()->dispatch('demo', $subjectB);
    $global = dispatcher()->dispatch('demo');

    expect(collect([$a->id, $b->id, $global->id])->unique())->toHaveCount(3);
    expect($a->refresh()->subject_id)->toBe(1);
    Queue::assertPushed(TrackedTestJob::class, 3);
});

test('a fresh run dispatches once the previous one has finished', function (): void {
    Queue::fake();

    $first = dispatcher()->dispatch('demo');
    $first->markSuccess('done');

    $second = dispatcher()->dispatch('demo');

    expect($second->id)->not->toBe($first->id);
    Queue::assertPushed(TrackedTestJob::class, 2);
});

test('an active run whose job has vanished is failed and superseded', function (): void {
    Queue::fake();

    $stuck = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::QUEUED]);
    TaskRun::query()->whereKey($stuck->id)->update(['updated_at' => now()->subDay()]);

    $fresh = dispatcher()->dispatch('demo');

    expect($fresh->id)->not->toBe($stuck->id);
    expect($stuck->refresh()->status)->toBe(TaskRun::FAILED);
    Queue::assertPushed(TrackedTestJob::class, 1);
});

test('a long-queued run is still reused while its job is on the queue', function (): void {
    Queue::fake();

    $first = dispatcher()->dispatch('demo');
    TaskRun::query()->whereKey($first->id)->update(['updated_at' => now()->subDay()]);

    $second = dispatcher()->dispatch('demo');

    expect($second->id)->toBe($first->id);
    expect($first->refresh()->status)->toBe(TaskRun::QUEUED);
    Queue::assertPushed(TrackedTestJob::class, 1);
});

test('a null orphan grace disables the guard entirely', function (): void {
    Queue::fake();
    config()->set('task-runs.orphan_grace_seconds', null);

    $stuck = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::QUEUED]);
    TaskRun::query()->whereKey($stuck->id)->update(['updated_at' => now()->subDay()]);

    expect(dispatcher()->dispatch('demo')->id)->toBe($stuck->id);
});

test('queue and connection resolve from config maps', function (): void {
    Queue::fake();
    config()->set('task-runs.queues.demo', 'heavy');
    config()->set('task-runs.connections', ['heavy' => 'redis-long', '*' => 'redis']);

    dispatcher()->dispatch('demo');

    Queue::assertPushed(TrackedTestJob::class, fn (TrackedTestJob $job): bool => $job->queue === 'heavy' && $job->connection === 'redis-long');
});

test('the wildcard connection covers unlisted queues', function (): void {
    Queue::fake();
    config()->set('task-runs.connections', ['*' => 'redis']);

    dispatcher()->dispatch('demo');

    Queue::assertPushed(TrackedTestJob::class, fn (TrackedTestJob $job): bool => $job->queue === null && $job->connection === 'redis');
});

test('resolveConnectionUsing overrides the config map', function (): void {
    Queue::fake();
    config()->set('task-runs.connections', ['*' => 'redis']);
    TaskDispatcher::resolveConnectionUsing(fn (?string $queue): string => 'redis-long');

    dispatcher()->dispatch('demo');

    Queue::assertPushed(TrackedTestJob::class, fn (TrackedTestJob $job): bool => $job->connection === 'redis-long');
});

test('types lists the configured map in order', function (): void {
    expect(dispatcher()->types())->toBe(['demo', 'coarse']);
});
