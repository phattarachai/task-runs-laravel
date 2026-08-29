<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Support;

use Throwable;

/**
 * Lightweight read of the Horizon master-supervisor state, mirroring `horizon:status` — a queued
 * task sits forever if no worker is up, so the tasks screen surfaces worker health instead of
 * letting a run look merely slow. Referenced by string so Horizon stays optional: without the
 * package the status is `unavailable`; with it but Redis unreachable, `unknown`.
 */
class HorizonStatus
{
    private const string REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

    /**
     * @return array{status: 'running'|'paused'|'inactive'|'unknown'|'unavailable', masters: int}
     */
    public static function current(): array
    {
        if (! interface_exists(self::REPOSITORY)) {
            return ['status' => 'unavailable', 'masters' => 0];
        }

        try {
            /** @var object{all: callable} $repository */
            $repository = app(self::REPOSITORY);
            $masters = collect($repository->all());
        } catch (Throwable) {
            return ['status' => 'unknown', 'masters' => 0];
        }

        $status = match (true) {
            $masters->isEmpty() => 'inactive',
            $masters->contains(fn (object $master): bool => $master->status === 'paused') => 'paused',
            default => 'running',
        };

        return ['status' => $status, 'masters' => $masters->count()];
    }
}
