<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Phattarachai\TaskRunsLaravel\Models\TaskRun;

/**
 * Serializes {@see TaskRun::snapshot()} — the one wire shape the initial render, the poll
 * endpoint, and the broadcast event all share.
 *
 * @mixin TaskRun
 */
class TaskRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return $this->resource->snapshot();
    }
}
