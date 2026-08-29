<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\InterruptibleTestJob;

afterEach(fn () => InterruptibleTestJob::$onStep = null);

test('a job with budget and no restart signal runs to completion', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo']);

    new InterruptibleTestJob($run)->handle();

    expect($run->refresh()->status)->toBe(TaskRun::SUCCESS);
    expect($run->processed)->toBe(10);
});

test('a restart signal mid-run re-queues the run and re-dispatches the job', function (): void {
    Queue::fake();
    $run = TaskRun::query()->create(['type' => 'demo']);

    $job = new InterruptibleTestJob($run);
    $job->onConnection('redis-long')->onQueue('heavy');

    InterruptibleTestJob::$onStep = function (int $step): void {
        if ($step === 4) {
            Cache::forever('illuminate:queue:restart', 'now');
        }
    };

    $job->handle();

    $run->refresh();
    expect($run->status)->toBe(TaskRun::QUEUED);
    expect($run->message)->toContain('queue restart');
    expect($run->processed)->toBe(3);

    Queue::assertPushed(InterruptibleTestJob::class, fn (InterruptibleTestJob $pushed): bool => $pushed->queue === 'heavy' && $pushed->connection === 'redis-long');
});

test('an exhausted run budget yields with the timeout message', function (): void {
    Queue::fake();
    $run = TaskRun::query()->create(['type' => 'demo']);

    $job = new InterruptibleTestJob($run);
    $job->timeout = 0;

    $job->handle();

    $run->refresh();
    expect($run->status)->toBe(TaskRun::QUEUED);
    expect($run->message)->toContain('worker timeout');

    Queue::assertPushed(InterruptibleTestJob::class, 1);
});
