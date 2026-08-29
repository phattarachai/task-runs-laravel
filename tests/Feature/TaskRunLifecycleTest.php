<?php

declare(strict_types=1);

use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\TaskRuns;

test('markRunning sets status, started_at, total, and bumps attempts', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo']);

    $run->markRunning(42);

    expect($run->refresh()->status)->toBe(TaskRun::RUNNING);
    expect($run->total)->toBe(42);
    expect($run->attempts)->toBe(1);
    expect($run->started_at)->not->toBeNull();

    $run->markRunning();

    expect($run->refresh()->attempts)->toBe(2);
    expect($run->total)->toBe(42);
});

test('advance bumps the counter and keeps the last message when none is given', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    $run->advance(2, 'halfway');
    $run->advance();

    expect($run->refresh()->processed)->toBe(3);
    expect($run->message)->toBe('halfway');
});

test('the finishers stamp finished_at and their status', function (string $method, array $arguments, string $status): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    $run->{$method}(...$arguments);

    expect($run->refresh()->status)->toBe($status);
    expect($run->finished_at)->not->toBeNull();
})->with([
    'success' => ['markSuccess', ['done'], TaskRun::SUCCESS],
    'failed' => ['markFailed', ['boom'], TaskRun::FAILED],
    'cancelled' => ['markCancelled', [], TaskRun::CANCELLED],
]);

test('markCancelled without a message records a default', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    $run->markCancelled();

    expect($run->refresh()->message)->toBe('Cancelled.');
});

test('markRequeued re-arms the run as queued and keeps progress', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING, 'processed' => 7, 'total' => 10]);

    $run->markRequeued('yielding');

    $run->refresh();
    expect($run->status)->toBe(TaskRun::QUEUED);
    expect($run->processed)->toBe(7);
    expect($run->finished_at)->toBeNull();
});

test('requestCancel flips the cooperative flag isCancelled reads back', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    expect($run->isCancelled())->toBeFalse();

    $run->requestCancel();

    expect($run->isCancelled())->toBeTrue();
});

test('the active scope covers queued and running only', function (): void {
    TaskRun::query()->create(['type' => 'a', 'status' => TaskRun::QUEUED]);
    TaskRun::query()->create(['type' => 'b', 'status' => TaskRun::RUNNING]);
    TaskRun::query()->create(['type' => 'c', 'status' => TaskRun::SUCCESS]);
    TaskRun::query()->create(['type' => 'd', 'status' => TaskRun::FAILED]);
    TaskRun::query()->create(['type' => 'e', 'status' => TaskRun::CANCELLED]);

    expect(TaskRun::query()->active()->pluck('type')->all())->toBe(['a', 'b']);
});

test('snapshot is the full wire shape', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'options' => ['all' => true]]);
    $run->markRunning(5);

    expect(array_keys($run->refresh()->snapshot()))->toBe([
        'id', 'type', 'status', 'subject_type', 'subject_id', 'total', 'processed', 'attempts',
        'message', 'progress', 'dispatched_by', 'cancel_requested', 'started_at', 'finished_at', 'created_at',
    ]);
});

test('a run with nothing narrated yet snapshots an empty progress trail', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo']);

    expect($run->snapshot()['progress'])->toBe([]);
});

test('the model class is swappable via config', function (): void {
    expect(TaskRuns::model())->toBe(TaskRun::class);

    config()->set('task-runs.model', TaskRun::class);

    expect(TaskRuns::query()->getModel())->toBeInstanceOf(TaskRun::class);
});

test('the table name follows config', function (): void {
    expect(new TaskRun()->getTable())->toBe('task_runs');
});
