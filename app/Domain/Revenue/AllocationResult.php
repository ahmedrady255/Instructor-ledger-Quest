<?php

namespace App\Domain\Revenue;

use App\Domain\Money\Money;

final readonly class AllocationResult
{
    /**
     * @param  array<int, Money>  $instructorAllocations
     */
    public function __construct(
        public Money $platformShare,
        public Money $instructorPool,
        public array $instructorAllocations,
    ) {}
}
