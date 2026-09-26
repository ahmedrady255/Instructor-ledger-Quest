<?php

namespace App\Application\Ledger;

use App\Models\InstructorBalanceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefreshInstructorBalanceSnapshot
{
    public function __construct(private CalculateInstructorBalance $calculator) {}

    public function handle(int $instructorId, string $currency): InstructorBalanceSnapshot
    {
        $asOf = CarbonImmutable::now('UTC');
        $balance = $this->calculator->for($instructorId, $currency, $asOf);

        return DB::transaction(function () use ($instructorId, $currency, $asOf, $balance) {
            InstructorBalanceSnapshot::query()->updateOrCreate(
                ['instructor_id' => $instructorId, 'currency' => $currency],
                [
                    'earned_minor' => $balance['earned_minor'],
                    'adjusted_minor' => $balance['adjusted_minor'],
                    'paid_minor' => $balance['paid_minor'],
                    'reserved_minor' => $balance['reserved_minor'],
                    'outstanding_minor' => $balance['outstanding_minor'],
                    'as_of' => $asOf,
                ],
            );

            DB::afterCommit(function () use ($instructorId, $currency, $balance, $asOf) {
                try {
                    Cache::put(
                        "balance:{$instructorId}:{$currency}",
                        [...$balance, 'as_of' => $asOf->toIso8601String()],
                        now()->addMinutes(15),
                    );
                } catch (Throwable) {
                    // The MySQL snapshot remains authoritative when Redis is unavailable.
                }
            });

            return InstructorBalanceSnapshot::query()
                ->where(['instructor_id' => $instructorId, 'currency' => $currency])
                ->firstOrFail();
        });
    }
}
