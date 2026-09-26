<?php

namespace App\Domain\Revenue;

use InvalidArgumentException;

final readonly class InstructorWeight
{
    public function __construct(
        public int $instructorId,
        public int $weight,
    ) {
        if ($instructorId <= 0) {
            throw new InvalidArgumentException('Instructor ID must be positive.');
        }

        if ($weight <= 0) {
            throw new InvalidArgumentException('Instructor weight must be positive.');
        }
    }
}
