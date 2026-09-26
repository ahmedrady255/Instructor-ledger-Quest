<?php

namespace App\Domain\Revenue;

use Brick\Math\BigInteger;
use InvalidArgumentException;

final class RefundAllocator
{
    /**
     * @param  array<int, int>  $capacities
     * @return array<int, int>
     */
    public function allocate(int $amount, array $capacities): array
    {
        if ($amount < 0 || array_filter($capacities, fn (int $capacity) => $capacity < 0)) {
            throw new InvalidArgumentException('Refund amounts and capacities cannot be negative.');
        }

        ksort($capacities, SORT_NUMERIC);
        $total = array_sum($capacities);
        if ($amount > $total) {
            throw new InvalidArgumentException('Refund exceeds remaining capacity.');
        }

        $allocated = array_fill_keys(array_keys($capacities), 0);
        if ($amount === 0) {
            return $allocated;
        }

        $remainders = [];
        foreach ($capacities as $key => $capacity) {
            [$share, $remainder] = BigInteger::of($amount)->multipliedBy($capacity)->quotientAndRemainder($total);
            $allocated[$key] = $share->toInt();
            $remainders[$key] = $remainder->toInt();
        }

        $left = $amount - array_sum($allocated);
        uksort($remainders, fn (int $leftKey, int $rightKey) => $remainders[$rightKey] <=> $remainders[$leftKey] ?: $leftKey <=> $rightKey);
        foreach (array_keys($remainders) as $key) {
            if ($left-- === 0) {
                break;
            }
            $allocated[$key]++;
        }

        ksort($allocated, SORT_NUMERIC);

        return $allocated;
    }
}
