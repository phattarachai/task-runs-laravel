<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Override;

class TaskRunsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/task-runs.php', 'task-runs');

        $this->app->singleton(TaskDispatcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ((bool) config('task-runs.routes.enabled', true)) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerPruneSchedule();
        }
    }

    private function registerRoutes(): void
    {
        Route::group([
            'prefix' => (string) config('task-runs.routes.prefix', 'tasks'),
            'middleware' => (array) config('task-runs.routes.middleware', ['web', 'auth']),
            'as' => 'task-runs.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/task-runs.php' => config_path('task-runs.php'),
        ], 'task-runs-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'task-runs-migrations');

        // Only the page stub lives in the host tree — the module itself is reached through the
        // `@task-runs` Vite alias, so there is no second copy to drift.
        $this->publishes([
            __DIR__.'/../resources/js/pages/TaskRuns.jsx' => resource_path('js/pages/TaskRuns.jsx'),
        ], 'task-runs-inertia');
    }

    private function registerPruneSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('task-runs.retention_days') === null) {
                return;
            }

            $schedule->command('model:prune', ['--model' => [TaskRuns::model()]])->daily();
        });
    }
}
