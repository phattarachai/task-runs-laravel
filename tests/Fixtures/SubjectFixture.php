<?php

declare(strict_types=1);

namespace Phattarachai\TaskRunsLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A bare morph target for subject-scoped dispatcher tests — never persisted, only its
 * morph class + key are stored on the run.
 */
class SubjectFixture extends Model
{
    protected $guarded = [];
}
