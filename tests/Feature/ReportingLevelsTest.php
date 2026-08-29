<?php

declare(strict_types=1);

use Phattarachai\TaskRunsLaravel\Jobs\Middleware\TrackedJob;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\CoarseTestJob;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\TrackedTestJob;

afterEach(fn () => CoarseTestJob::$work = null);

function runCoarse(TaskRun $run): void
{
    $job = new CoarseTestJob($run);

    new TrackedJob()->handle($job, fn (CoarseTestJob $job) => $job->handle());
}

test('a trait consumer runs its full manual lifecycle', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo']);

    new TrackedTestJob($run)->handle();

    $run->refresh();
    expect($run->status)->toBe(TaskRun::SUCCESS);
    expect($run->processed)->toBe(3);
    expect($run->total)->toBe(3);
    expect($run->message)->toBe('Done.');
});

test('the trait failed() hook marks the run failed', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    new TrackedTestJob($run)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(TaskRun::FAILED);
    expect($run->message)->toBe('boom');
});

test('the trait failed() hook copes with a null exception', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    new TrackedTestJob($run)->failed(null);

    expect($run->refresh()->message)->toBe('Task failed.');
});

test('a trait consumer no-ops when its run was cancelled while queued', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'cancel_requested' => true]);

    new TrackedTestJob($run)->handle();

    $run->refresh();
    expect($run->status)->toBe(TaskRun::CANCELLED);
    expect($run->started_at)->toBeNull();
});

test('the middleware wraps a coarse job in running then success', function (): void {
    $run = TaskRun::query()->create(['type' => 'coarse']);

    $seen = null;
    CoarseTestJob::$work = function (TaskRun $run) use (&$seen): void {
        $seen = $run->refresh()->status;
    };

    runCoarse($run);

    expect($seen)->toBe(TaskRun::RUNNING);
    $run->refresh();
    expect($run->status)->toBe(TaskRun::SUCCESS);
    expect($run->attempts)->toBe(1);
    expect($run->finished_at)->not->toBeNull();
});

test('the middleware marks a throwing job failed and rethrows', function (): void {
    $run = TaskRun::query()->create(['type' => 'coarse']);
    CoarseTestJob::$work = function (): void {
        throw new RuntimeException('exploded');
    };

    expect(fn () => runCoarse($run))->toThrow(RuntimeException::class, 'exploded');

    expect($run->refresh()->status)->toBe(TaskRun::FAILED);
    expect($run->message)->toBe('exploded');
});

test('the middleware skips the work when the run was cancelled while queued', function (): void {
    $run = TaskRun::query()->create(['type' => 'coarse', 'cancel_requested' => true]);

    $ran = false;
    CoarseTestJob::$work = function () use (&$ran): void {
        $ran = true;
    };

    runCoarse($run);

    expect($ran)->toBeFalse();
    expect($run->refresh()->status)->toBe(TaskRun::CANCELLED);
});

test('the middleware keeps a verdict the job set itself', function (): void {
    $run = TaskRun::query()->create(['type' => 'coarse']);
    CoarseTestJob::$work = fn (TaskRun $run) => $run->markCancelled('Stopped midway.');

    runCoarse($run);

    expect($run->refresh()->status)->toBe(TaskRun::CANCELLED);
    expect($run->message)->toBe('Stopped midway.');
});

test('the middleware leaves a self-requeued run queued', function (): void {
    $run = TaskRun::query()->create(['type' => 'coarse']);
    CoarseTestJob::$work = fn (TaskRun $run) => $run->markRequeued('yielding');

    runCoarse($run);

    expect($run->refresh()->status)->toBe(TaskRun::QUEUED);
});

test('the middleware passes straight through for a job with no task run property', function (): void {
    $job = new class
    {
        public bool $ran = false;
    };

    new TrackedJob()->handle($job, function (object $job): void {
        $job->ran = true;
    });

    expect($job->ran)->toBeTrue();
});
