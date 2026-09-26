<?php

namespace App\Jobs;

use App\Application\Ledger\RefreshInstructorBalanceSnapshot;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Money\Money;
use App\Domain\Revenue\InstructorWeight;
use App\Domain\Revenue\RevenueAllocator;
use App\Models\InstructorLedgerEntry;
use App\Models\RefundAllocation;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecognizeRevenue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $paymentId)
    {
        $this->onQueue('recognition');
    }

    public function handle(): void
    {
        $allocator = app(RevenueAllocator::class);
        $refresh = app(RefreshInstructorBalanceSnapshot::class);

        while (true) {
            $affected = DB::transaction(function () use ($allocator) {
                $payment = SubscriptionPayment::query()->with('instructorShares')->find($this->paymentId);

                if (! $payment) {
                    return [];
                }

                $items = RevenueScheduleItem::query()
                    ->where('payment_id', $this->paymentId)
                    ->where('status', 'PENDING')
                    ->where('service_date', '<=', CarbonImmutable::now('UTC')->toDateString())
                    ->orderBy('id')
                    ->limit(100)
                    ->lock('for update skip locked')
                    ->get();

                $weights = $payment->instructorShares
                    ->map(fn ($share) => new InstructorWeight($share->instructor_id, $share->weight))
                    ->all();
                $instructorIds = $payment->instructorShares->pluck('instructor_id')->all();

                foreach ($items as $item) {
                    $allocation = $allocator->allocate(new Money($item->instructor_pool_minor, $payment->currency), 0, $weights);
                    $cancelled = RefundAllocation::query()
                        ->where('schedule_item_id', $item->id)
                        ->where('kind', 'FUTURE_CANCELLATION')
                        ->selectRaw('instructor_id, SUM(amount_minor) AS amount_minor')
                        ->groupBy('instructor_id')
                        ->pluck('amount_minor', 'instructor_id');
                    $now = now();

                    foreach ($allocation->instructorAllocations as $instructorId => $money) {
                        $amount = max(0, $money->minor - (int) ($cancelled[$instructorId] ?? 0));

                        if ($amount === 0) {
                            continue;
                        }

                        InstructorLedgerEntry::query()->insertOrIgnore([
                            'instructor_id' => $instructorId,
                            'payment_id' => $payment->id,
                            'refund_id' => null,
                            'schedule_item_id' => $item->id,
                            'type' => LedgerEntryType::Earning->value,
                            'amount_minor' => $amount,
                            'currency' => $payment->currency,
                            'earned_at' => CarbonImmutable::parse($item->service_date, 'UTC')->startOfDay(),
                            'source_key' => "earning:{$payment->id}:{$item->service_date->toDateString()}:{$instructorId}",
                            'metadata' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $item->update(['status' => 'RECOGNIZED', 'recognized_at' => $now]);
                }

                return $items->isEmpty() ? [] : $instructorIds;
            });

            if ($affected === []) {
                break;
            }

            $payment = SubscriptionPayment::query()->find($this->paymentId);
            foreach ($affected as $instructorId) {
                $refresh->handle($instructorId, $payment->currency);
            }
        }
    }
}
