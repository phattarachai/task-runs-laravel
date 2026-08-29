<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Phattarachai\TaskRunsLaravel\Http\Controllers\TaskRunController;

Route::get('/poll', [TaskRunController::class, 'poll'])->name('poll');
Route::post('/run/{type}', [TaskRunController::class, 'run'])->name('run');
Route::post('/{taskRun}/cancel', [TaskRunController::class, 'cancel'])->whereNumber('taskRun')->name('cancel');

if (class_exists(Inertia\Inertia::class) && config('task-runs.ui.enabled', true) === true) {
    Route::get('/', [TaskRunController::class, 'index'])->name('index');
}
