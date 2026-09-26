<?php

namespace App\Application\Refunds;

use App\Application\Ledger\RefreshInstructorBalanceSnapshot;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Money\Money;
use App\Domain\Revenue\InstructorWeight;
use App\Domain\Revenue\RefundAllocator;
use App\Domain\Revenue\RevenueAllocator;
use App\Models\InstructorLedgerEntry;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\SubscriptionPayment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordRefund
{
    public function __construct(
        private RevenueAllocator $revenueAllocator,
        private RefundAllocator $refundAllocator,
        private RefreshInstructorBalanceSnapshot $refreshBalance,
    ) {}

    public function handle(array $attributes): Refund
    {
        return DB::transaction(function () use ($attributes) {
            $payment = SubscriptionPayment::query()
                ->with(['instructorShares', 'scheduleItems' => fn ($query) => $query->orderBy('service_date')])
                ->lockForUpdate()
                ->findOrFail($attributes['payment_id']);
            $existing = Refund::query()->where('provider_reference', $attributes['provider_reference'])->lockForUpdate()->first();

            if ($existing) {
                return $this->assertSameRefund($existing, $attributes);
            }

            $refunded = (int) Refund::query()->where('payment_id', $payment->id)->sum('amount_minor');
            if ($refunded + $attributes['amount_minor'] > $payment->amount_minor) {
                throw new DomainException('Cumulative refunds cannot exceed the payment amount.');
            }

            $weights = $payment->instructorShares
                ->map(fn ($share) => new InstructorWeight($share->instructor_id, $share->weight))
                ->all();
            $targetPool = $this->revenueAllocator->allocate(
                new Money($refunded + $attributes['amount_minor'], $payment->currency),
                $payment->platform_bps,
                [new InstructorWeight(1, 1)],
            )->instructorPool->minor;
            $alreadyAllocated = (int) RefundAllocation::query()
                ->whereIn('schedule_item_id', $payment->scheduleItems->pluck('id'))
                ->sum('amount_minor');
            $increment = $targetPool - $alreadyAllocated;

            $refund = Refund::query()->create([
                ...$attributes,
                'effective_at' => CarbonImmutable::parse($attributes['effective_at']),
            ]);
            $priorByDay = RefundAllocation::query()
                ->whereIn('schedule_item_id', $payment->scheduleItems->pluck('id'))
                ->selectRaw('schedule_item_id, SUM(amount_minor) AS amount_minor')
                ->groupBy('schedule_item_id')
                ->pluck('amount_minor', 'schedule_item_id');
            $dayCapacities = $payment->scheduleItems->mapWithKeys(fn ($item) => [
                $item->id => $item->instructor_pool_minor - (int) ($priorByDay[$item->id] ?? 0),
            ])->all();
            $dayAllocations = $this->refundAllocator->allocate($increment, $dayCapacities);
            $effectiveAt = CarbonImmutable::parse($attributes['effective_at']);

            foreach ($payment->scheduleItems as $item) {
                $dayAmount = $dayAllocations[$item->id];
                if ($dayAmount === 0) {
                    continue;
                }

                $originalShares = $this->revenueAllocator
                    ->allocate(new Money($item->instructor_pool_minor, $payment->currency), 0, $weights)
                    ->instructorAllocations;
                $priorByInstructor = RefundAllocation::query()
                    ->where('schedule_item_id', $item->id)
                    ->selectRaw('instructor_id, SUM(amount_minor) AS amount_minor')
                    ->groupBy('instructor_id')
                    ->pluck('amount_minor', 'instructor_id');
                $capacities = collect($originalShares)->mapWithKeys(fn ($money, $instructorId) => [
                    $instructorId => $money->minor - (int) ($priorByInstructor[$instructorId] ?? 0),
                ])->all();
                $instructorAllocations = $this->refundAllocator->allocate($dayAmount, $capacities);
                $kind = $item->status === 'PENDING' && $item->service_date->toDateString() >= $effectiveAt->toDateString()
                    ? 'FUTURE_CANCELLATION'
                    : 'RECOGNIZED_ADJUSTMENT';

                foreach ($instructorAllocations as $instructorId => $amount) {
                    if ($amount === 0) {
                        continue;
                    }

                    $sourceKey = "refund:{$refund->id}:{$item->id}:{$instructorId}:{$kind}";
                    RefundAllocation::query()->create([
                        'refund_id' => $refund->id,
                        'schedule_item_id' => $item->id,
                        'instructor_id' => $instructorId,
                        'kind' => $kind,
                        'amount_minor' => $amount,
                        'source_key' => $sourceKey,
                    ]);

                    if ($kind === 'RECOGNIZED_ADJUSTMENT') {
                        InstructorLedgerEntry::query()->create([
                            'instructor_id' => $instructorId,
                            'payment_id' => $payment->id,
                            'refund_id' => $refund->id,
                            'schedule_item_id' => $item->id,
                            'type' => LedgerEntryType::RefundAdjustment,
                            'amount_minor' => -$amount,
                            'currency' => $payment->currency,
                            'earned_at' => $effectiveAt,
                            'source_key' => "refund-adjustment:{$refund->id}:{$item->id}:{$instructorId}",
                            'metadata' => null,
                        ]);
                    }
                }
            }

            $instructorIds = $payment->instructorShares->pluck('instructor_id')->all();
            DB::afterCommit(function () use ($instructorIds, $payment) {
                foreach ($instructorIds as $instructorId) {
                    $this->refreshBalance->handle($instructorId, $payment->currency);
                }
            });

            return $refund;
        });
    }

    private function assertSameRefund(Refund $refund, array $attributes): Refund
    {
        if ($refund->payment_id !== $attributes['payment_id']
            || $refund->amount_minor !== $attributes['amount_minor']
            || ! $refund->effective_at->equalTo(CarbonImmutable::parse($attributes['effective_at']))
            || $refund->reason !== ($attributes['reason'] ?? null)) {
            throw new DomainException('Provider reference already belongs to a different refund.');
        }

        $refund->wasRecentlyCreated = false;

        return $refund;
    }
}
