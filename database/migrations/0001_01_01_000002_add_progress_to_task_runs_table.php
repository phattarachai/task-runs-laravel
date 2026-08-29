<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            // An append-only list of {at, line} entries — the narrated trail behind the
            // single `message` line. Goes away with the row when the run is pruned.
            $table->jsonb('progress')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('progress');
        });
    }

    private function table(): string
    {
        return (string) config('task-runs.table', 'task_runs');
    }
};
