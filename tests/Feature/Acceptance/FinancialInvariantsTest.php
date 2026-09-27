<?php

use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Money\Money;
use App\Domain\Payouts\PayoutStatus;
use App\Domain\Revenue\InstructorWeight;
use App\Domain\Revenue\RecognitionScheduleBuilder;
use App\Domain\Revenue\RevenueAllocator;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\InstructorLedgerDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('conserves randomized allocations and schedules', function () {
    $allocator = new RevenueAllocator;
    $schedule = new RecognitionScheduleBuilder;

    mt_srand(20260927);
    for ($case = 0; $case < 100; $case++) {
        $amount = mt_rand(1, 1_000_000);
        $bps = mt_rand(0, 10_000);
        $weights = array_map(fn (int $id) => new InstructorWeight($id, mt_rand(1, 100)), range(1, mt_rand(1, 10)));
        $allocation = $allocator->allocate(new Money($amount, 'EGP'), $bps, $weights);
        $days = $schedule->build(
            $allocation->instructorPool->minor,
            CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
            CarbonImmutable::parse('2024-01-01 00:00:00 UTC')->addDays(mt_rand(1, 730)),
        );

        expect($allocation->platformShare->minor + array_sum(array_map(fn (Money $money) => $money->minor, $allocation->instructorAllocations)))->toBe($amount)
            ->and(array_sum(array_map(fn ($day) => $day->instructorPoolMinor, $days)))->toBe($allocation->instructorPool->minor);
    }
});

it('reports daily control totals by currency', function () {
    $instructor = User::factory()->create();
    $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
    $schedule = RevenueScheduleItem::factory()->create(['payment_id' => $payment->id]);
    $first = controlEntry($instructor, $payment, $schedule, 600, LedgerEntryType::Earning, 'control-1');
    $second = controlEntry($instructor, $payment, $schedule, 400, LedgerEntryType::Earning, 'control-2');
    controlEntry($instructor, $payment, $schedule, -200, LedgerEntryType::RefundAdjustment, 'control-adjustment');
    $paid = Payout::factory()->create([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'status' => PayoutStatus::Succeeded,
        'completed_at' => '2026-01-10 00:00:00',
        'created_at' => '2026-01-09 00:00:00',
    ]);
    PayoutItem::factory()->create(['payout_id' => $paid->id, 'ledger_entry_id' => $first->id, 'amount_minor' => 100]);
    $reserved = Payout::factory()->create([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'status' => PayoutStatus::Unknown,
        'created_at' => '2026-01-09 00:00:00',
    ]);
    PayoutItem::factory()->create(['payout_id' => $reserved->id, 'ledger_entry_id' => $second->id, 'amount_minor' => 200]);

    $this->artisan('instructors:ledger-control --date=2026-01-31')
        ->expectsTable(
            ['Currency', 'Recognized', 'Adjusted', 'Paid', 'Reserved', 'Outstanding'],
            [['EGP', '1000', '-200', '100', '200', '500']],
        )->assertSuccessful();
});

it('seeds one reproducible multi-instructor demonstration without duplication', function () {
    $this->seed(InstructorLedgerDemoSeeder::class);
    $counts = [
        SubscriptionPayment::query()->count(),
        RevenueScheduleItem::query()->count(),
        InstructorLedgerEntry::query()->count(),
    ];

    $this->seed(InstructorLedgerDemoSeeder::class);

    expect($counts[0])->toBe(1)
        ->and($counts[1])->toBeGreaterThan(1)
        ->and($counts[2])->toBeGreaterThan(1)
        ->and([
            SubscriptionPayment::query()->count(),
            RevenueScheduleItem::query()->count(),
            InstructorLedgerEntry::query()->count(),
        ])->toBe($counts);
});

function controlEntry(User $instructor, SubscriptionPayment $payment, RevenueScheduleItem $schedule, int $amount, LedgerEntryType $type, string $key): InstructorLedgerEntry
{
    return InstructorLedgerEntry::factory()->create([
        'instructor_id' => $instructor->id,
        'payment_id' => $payment->id,
        'schedule_item_id' => $schedule->id,
        'type' => $type,
        'amount_minor' => $amount,
        'currency' => 'EGP',
        'earned_at' => '2026-01-01 00:00:00',
        'source_key' => $key,
    ]);
}
