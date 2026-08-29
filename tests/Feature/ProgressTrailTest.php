<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Phattarachai\TaskRunsLaravel\Events\TaskRunProgress;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\TrackedTestJob;

test('reportProgress appends entries in order, each stamped with the time it landed', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    Carbon::setTestNow('2026-08-29 10:00:00');
    $run->reportProgress('เจอ Tax ID 0105558…');

    Carbon::setTestNow('2026-08-29 10:00:04');
    $run->reportProgress('ค้นคู่ค้าใน DB');

    $progress = $run->refresh()->progress;

    expect($progress)->toHaveCount(2)
        ->and(array_column($progress, 'line'))->toBe(['เจอ Tax ID 0105558…', 'ค้นคู่ค้าใน DB'])
        ->and($progress[0]['at'])->toBe(Carbon::parse('2026-08-29 10:00:00')->toIso8601String())
        ->and($progress[1]['at'])->toBe(Carbon::parse('2026-08-29 10:00:04')->toIso8601String());

    Carbon::setTestNow();
});

test('the trail is append-only — a stale in-memory copy cannot drop a line written elsewhere', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);
    $stale = TaskRun::query()->findOrFail($run->id);

    $run->reportProgress('first');
    $stale->reportProgress('second');

    expect(array_column((array) $run->refresh()->progress, 'line'))->toBe(['first', 'second']);
});

test('narration leaves the progress counter and the headline message alone', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING, 'processed' => 3]);

    $run->advance(1, 'halfway');
    $run->reportProgress('reading page 4');

    $run->refresh();

    expect($run->processed)->toBe(4)
        ->and($run->message)->toBe('halfway')
        ->and($run->status)->toBe(TaskRun::RUNNING);
});

test('progress_limit keeps the most recent lines and drops the oldest', function (): void {
    config()->set('task-runs.progress_limit', 3);

    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    foreach (range(1, 5) as $index) {
        $run->reportProgress("line {$index}");
    }

    expect(array_column((array) $run->refresh()->progress, 'line'))->toBe(['line 3', 'line 4', 'line 5']);
});

test('a null progress_limit keeps everything', function (): void {
    config()->set('task-runs.progress_limit', null);

    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);

    foreach (range(1, 12) as $index) {
        $run->reportProgress("line {$index}");
    }

    expect($run->refresh()->progress)->toHaveCount(12);
});

test('the trail rides in the snapshot the poll endpoint and the broadcast share', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);
    $run->reportProgress('reading invoice.jpg');

    $snapshot = $run->refresh()->snapshot();

    expect($snapshot['progress'])->toHaveCount(1)
        ->and($snapshot['progress'][0]['line'])->toBe('reading invoice.jpg');

    $this->getJson(route('task-runs.poll'))
        ->assertOk()
        ->assertJsonPath('active.0.progress.0.line', 'reading invoice.jpg');
});

test('the trail goes away with the row when the run is pruned', function (): void {
    config()->set('task-runs.retention_days', 7);

    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);
    $run->reportProgress('narrated once');
    $run->markSuccess();

    TaskRun::query()->whereKey($run->id)->update(['created_at' => now()->subDays(8)]);

    $this->artisan('model:prune', ['--model' => [TaskRun::class]])->assertSuccessful();

    expect(TaskRun::query()->count())->toBe(0);
});

test('reportProgress broadcasts the new entry with the run id and status when enabled', function (): void {
    config()->set('task-runs.broadcast.enabled', true);
    Event::fake([TaskRunProgress::class]);

    $run = TaskRun::query()->create(['type' => 'demo', 'status' => TaskRun::RUNNING]);
    $run->reportProgress('reading invoice.jpg');

    Event::assertDispatchedTimes(TaskRunProgress::class, 1);
    Event::assertDispatched(TaskRunProgress::class, fn (TaskRunProgress $event): bool => $event->taskRunId === $run->id
        && $event->status === TaskRun::RUNNING
        && $event->entry['line'] === 'reading invoice.jpg'
        && array_key_exists('at', $event->entry));
});

test('nothing is fired when broadcasting is disabled — poll-only hosts stay quiet', function (): void {
    Event::fake([TaskRunProgress::class]);

    TaskRun::query()->create(['type' => 'demo'])->reportProgress('quiet');

    Event::assertNotDispatched(TaskRunProgress::class);
});

test('the progress event rides a channel of its own, per run, under a stable name', function (): void {
    config()->set('task-runs.broadcast.channel', 'ops');

    $event = new TaskRunProgress(42, TaskRun::RUNNING, ['at' => '2026-08-29T10:00:00+07:00', 'line' => 'hello']);

    expect($event->broadcastAs())->toBe('TaskRunProgress')
        ->and($event->broadcastWith())->toBe([
            'id' => 42,
            'status' => TaskRun::RUNNING,
            'entry' => ['at' => '2026-08-29T10:00:00+07:00', 'line' => 'hello'],
        ])
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and((string) $event->broadcastOn()[0]->name)->toBe('private-ops.42')
        ->and(TaskRunProgress::channelName('__ID__'))->toBe('ops.__ID__');
});

test('a public-channel host gets a plain channel', function (): void {
    config()->set('task-runs.broadcast.private', false);

    $event = new TaskRunProgress(7, TaskRun::QUEUED, ['at' => 'now', 'line' => 'x']);

    expect($event->broadcastOn()[0])->toBeInstanceOf(Channel::class)
        ->and((string) $event->broadcastOn()[0]->name)->toBe('task-runs.7');
});

test('the job trait narrates through to the run alongside the counter', function (): void {
    $run = TaskRun::query()->create(['type' => 'demo']);

    new TrackedTestJob($run)->handle();

    $run->refresh();

    expect(array_column((array) $run->progress, 'line'))
        ->toBe(['narrating step 1', 'narrating step 2', 'narrating step 3'])
        ->and($run->processed)->toBe(3);
});
