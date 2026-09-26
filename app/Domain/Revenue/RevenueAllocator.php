<?php

namespace App\Domain\Revenue;

use App\Domain\Money\Money;
use Brick\Math\BigInteger;
use InvalidArgumentException;
use LogicException;

final class RevenueAllocator
{
    /**
     * @param  list<InstructorWeight>  $weights
     */
    public function allocate(Money $payment, int $platformBps, array $weights): AllocationResult
    {
        if ($payment->minor < 0) {
            throw new InvalidArgumentException('Payment amount cannot be negative.');
        }

        if ($platformBps < 0 || $platformBps > 10_000) {
            throw new InvalidArgumentException('Platform basis points must be between 0 and 10000.');
        }

        if ($weights === []) {
            throw new InvalidArgumentException('At least one instructor is required.');
        }

        $byInstructor = [];
        $totalWeight = 0;

        foreach ($weights as $weight) {
            if (! $weight instanceof InstructorWeight) {
                throw new InvalidArgumentException('Every weight must be an InstructorWeight.');
            }

            if (isset($byInstructor[$weight->instructorId])) {
                throw new InvalidArgumentException('Instructor IDs must be unique.');
            }

            if ($totalWeight > PHP_INT_MAX - $weight->weight) {
                throw new InvalidArgumentException('Total instructor weight is too large.');
            }

            $byInstructor[$weight->instructorId] = $weight;
            $totalWeight += $weight->weight;
        }

        ksort($byInstructor, SORT_NUMERIC);

        $platformMinor = intdiv($payment->minor, 10_000) * $platformBps
            + intdiv(($payment->minor % 10_000) * $platformBps, 10_000);
        $poolMinor = $payment->minor - $platformMinor;
        $basePool = intdiv($poolMinor, $totalWeight);
        $poolRemainder = $poolMinor % $totalWeight;
        $shares = [];
        $remainders = [];

        foreach ($byInstructor as $instructorId => $weight) {
            [$remainderShare, $remainder] = BigInteger::of($poolRemainder)
                ->multipliedBy($weight->weight)
                ->quotientAndRemainder($totalWeight);
            $share = $basePool * $weight->weight + $remainderShare->toInt();
            $shares[$instructorId] = $share;
            $remainders[$instructorId] = $remainder->toInt();
        }

        $unitsLeft = $poolMinor - array_sum($shares);
        uksort($remainders, function (int $left, int $right) use ($remainders): int {
            return $remainders[$right] <=> $remainders[$left] ?: $left <=> $right;
        });

        foreach (array_keys($remainders) as $instructorId) {
            if ($unitsLeft-- === 0) {
                break;
            }

            $shares[$instructorId]++;
        }

        ksort($shares, SORT_NUMERIC);

        if ($platformMinor + array_sum($shares) !== $payment->minor) {
            throw new LogicException('Allocation did not conserve the payment amount.');
        }

        return new AllocationResult(
            new Money($platformMinor, $payment->currency),
            new Money($poolMinor, $payment->currency),
            array_map(fn (int $minor) => new Money($minor, $payment->currency), $shares),
        );
    }
}
