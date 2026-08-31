<?php

declare(strict_types=1);

use Phattarachai\TaskRunsLaravel\Models\TaskRun;

test('the request column defaults to null and casts to an array', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    expect($run->refresh()->request)->toBeNull();

    $run->recordRequest(['prompt' => 'read the invoice', 'params' => ['model' => 'opus']]);

    expect($run->refresh()->request)->toBeArray();
});

test('recordRequest persists the payload and reads it back as an array', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    $run->recordRequest(['prompt' => 'read the invoice', 'params' => ['model' => 'opus', 'temperature' => 0]]);

    expect($run->refresh()->request)->toBe([
        'prompt' => 'read the invoice',
        'params' => ['model' => 'opus', 'temperature' => 0],
    ]);
});

test('the request payload stays out of the snapshot the poll list shares', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);
    $run->recordRequest(['prompt' => 'read the invoice']);

    expect($run->refresh()->snapshot())->not->toHaveKey('request');
});
