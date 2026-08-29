<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\TaskRunsLaravel\TaskRunsServiceProvider;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\CoarseTestJob;
use Phattarachai\TaskRunsLaravel\Tests\Fixtures\TrackedTestJob;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            TaskRunsServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('view.paths', [__DIR__.'/resources/views']);
        $app['config']->set('inertia.testing.ensure_pages_exist', false);

        $app['config']->set('task-runs.routes.middleware', ['web']);
        $app['config']->set('task-runs.jobs', [
            'demo' => TrackedTestJob::class,
            'coarse' => CoarseTestJob::class,
        ]);
    }
}
