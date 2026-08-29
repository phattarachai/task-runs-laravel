<?php

declare(strict_types=1);

use Phattarachai\TaskRunsLaravel\Models\TaskRun;

test('finished runs older than the retention window are pruned, active and recent ones kept', function (): void {
    config()->set('task-runs.retention_days', 7);

    $oldFinished = TaskRun::query()->create(['type' => 'a', 'status' => TaskRun::SUCCESS]);
    $oldActive = TaskRun::query()->create(['type' => 'b', 'status' => TaskRun::RUNNING]);
    $recent = TaskRun::query()->create(['type' => 'c', 'status' => TaskRun::FAILED]);

    TaskRun::query()->whereKey($oldFinished->id)->update(['created_at' => now()->subDays(8)]);
    TaskRun::query()->whereKey($oldActive->id)->update(['created_at' => now()->subDays(8)]);

    $this->artisan('model:prune', ['--model' => [TaskRun::class]])->assertSuccessful();

    expect(TaskRun::query()->pluck('type')->all())->toBe(['b', 'c']);
});

test('a null retention keeps everything', function (): void {
    config()->set('task-runs.retention_days', null);

    $old = TaskRun::query()->create(['type' => 'a', 'status' => TaskRun::SUCCESS]);
    TaskRun::query()->whereKey($old->id)->update(['created_at' => now()->subYears(3)]);

    $this->artisan('model:prune', ['--model' => [TaskRun::class]])->assertSuccessful();

    expect(TaskRun::query()->count())->toBe(1);
});
