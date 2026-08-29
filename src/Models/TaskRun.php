<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;
use Phattarachai\TaskRunsLaravel\Events\TaskRunStatusChanged;

/**
 * One execution of a background task type, with live progress and queryable history — distinct
 * from Laravel's `jobs` / `failed_jobs`, which track delivery, not domain progress.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property int|null $total
 * @property int $processed
 * @property int $attempts
 * @property string|null $message
 * @property array<string, mixed>|null $options
 * @property string|null $dispatched_by
 * @property bool $cancel_requested
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaskRun extends Model
{
    use MassPrunable;

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string SUCCESS = 'success';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    #[Override]
    public function getTable(): string
    {
        return (string) config('task-runs.table', 'task_runs');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function markRunning(?int $total = null): void
    {
        $this->forceFill([
            'status' => self::RUNNING,
            'total' => $total ?? $this->total,
            'attempts' => $this->attempts + 1,
            'started_at' => now(),
        ])->save();

        $this->broadcastState();
    }

    /**
     * Bump the progress counter (and optionally the last status line). Callers throttle how often
     * they invoke this so a huge batch doesn't hammer the DB.
     */
    public function advance(int $by = 1, ?string $message = null): void
    {
        $this->forceFill([
            'processed' => $this->processed + $by,
            'message' => $message ?? $this->message,
        ])->save();

        $this->broadcastState();
    }

    public function markSuccess(?string $message = null): void
    {
        $this->finish(self::SUCCESS, $message);
    }

    public function markFailed(string $message): void
    {
        $this->finish(self::FAILED, $message);
    }

    public function markCancelled(?string $message = null): void
    {
        $this->finish(self::CANCELLED, $message ?? 'Cancelled.');
    }

    /**
     * Re-arm the run as queued without finishing it — a job yielding to a worker restart
     * re-dispatches itself and a fresh worker resumes the remaining work.
     */
    public function markRequeued(?string $message = null): void
    {
        $this->forceFill([
            'status' => self::QUEUED,
            'message' => $message ?? $this->message,
        ])->save();

        $this->broadcastState();
    }

    public function requestCancel(): void
    {
        $this->forceFill(['cancel_requested' => true])->save();

        $this->broadcastState();
    }

    /**
     * Whether a cancel was requested — jobs check this cooperatively between chunks.
     */
    public function isCancelled(): bool
    {
        return (bool) $this->refresh()->cancel_requested;
    }

    /**
     * The wire shape shared by the resource and the broadcast event — one source of truth for
     * what a run looks like on the client.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'total' => $this->total,
            'processed' => $this->processed,
            'attempts' => $this->attempts,
            'message' => $this->message,
            'dispatched_by' => $this->dispatched_by,
            'cancel_requested' => $this->cancel_requested,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('task-runs.retention_days');

        if ($days === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->whereNotIn('status', [self::QUEUED, self::RUNNING])
            ->where('created_at', '<', now()->subDays((int) $days));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'processed' => 'integer',
            'attempts' => 'integer',
            'options' => 'array',
            'cancel_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::RUNNING]);
    }

    private function finish(string $status, ?string $message): void
    {
        $this->forceFill([
            'status' => $status,
            'message' => $message ?? $this->message,
            'finished_at' => now(),
        ])->save();

        $this->broadcastState();
    }

    private function broadcastState(): void
    {
        if (config('task-runs.broadcast.enabled') !== true) {
            return;
        }

        event(new TaskRunStatusChanged($this->snapshot()));
    }
}
