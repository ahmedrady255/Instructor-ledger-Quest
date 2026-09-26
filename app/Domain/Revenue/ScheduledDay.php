<?php

namespace App\Domain\Revenue;

use Carbon\CarbonImmutable;

final readonly class ScheduledDay
{
    public function __construct(
        public CarbonImmutable $date,
        public int $instructorPoolMinor,
    ) {}
}
