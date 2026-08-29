<?php

declare(strict_types=1);

use Phattarachai\TaskRunsLaravel\Support\HorizonStatus;

test('without Horizon installed the status degrades to unavailable', function (): void {
    expect(HorizonStatus::current())->toBe(['status' => 'unavailable', 'masters' => 0]);
});
