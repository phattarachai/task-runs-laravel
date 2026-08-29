<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel;

use Illuminate\Database\Eloquent\Builder;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;

/**
 * Resolves the configured TaskRun model class so hosts can substitute their own subclass.
 */
class TaskRuns
{
    /**
     * @return class-string<TaskRun>
     */
    public static function model(): string
    {
        /** @var class-string<TaskRun> */
        return (string) config('task-runs.model', TaskRun::class);
    }

    /**
     * @return Builder<TaskRun>
     */
    public static function query(): Builder
    {
        return self::model()::query();
    }
}
