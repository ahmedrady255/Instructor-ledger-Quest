<?php

use App\Application\Ledger\CalculateInstructorBalance;
use App\Application\Ledger\RefreshInstructorBalanceSnapshot;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Payouts\PayoutStatus;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('calculates earned adjusted paid reserved outstanding and deficit at a point in time', function () {
    $asOf = CarbonImmutable::parse('2026-01-10 12:00:00 UTC');
    $instructor = User::factory()->create();
    $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
    $schedule = RevenueScheduleItem::factory()->create(['payment_id' => $payment->id]);
    $first = ledgerEntry($instructor, $payment, $schedule, 600, LedgerEntryType::Earning, 'earning-1');
    $second = ledgerEntry($instructor, $payment, $schedule, 400, LedgerEntryType::Earning, 'earning-2');
    ledgerEntry($instructor, $payment, $schedule, -100, LedgerEntryType::RefundAdjustment, 'refund-1');
    ledgerEntry($instructor, $payment, $schedule, 999, LedgerEntryType::Earning, 'future', '2026-01-11 00:00:00');

    $paid = Payout::factory()->create([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'status' => PayoutStatus::Succeeded,
        'completed_at' => '2026-01-09 00:00:00',
    ]);
    PayoutItem::factory()->create(['payout_id' => $paid->id, 'ledger_entry_id' => $first->id, 'amount_minor' => 200]);
    $reserved = Payout::factory()->create([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'status' => PayoutStatus::Unknown,
        'created_at' => '2026-01-09 00:00:00',
    ]);
    PayoutItem::factory()->create(['payout_id' => $reserved->id, 'ledger_entry_id' => $second->id, 'amount_minor' => 100]);

    expect(app(CalculateInstructorBalance::class)->for($instructor->id, 'EGP', $asOf))->toBe([
        'earned_minor' => 1000,
        'adjusted_minor' => -100,
        'net_earned_minor' => 900,
        'paid_minor' => 200,
        'reserved_minor' => 100,
        'outstanding_minor' => 600,
        'deficit_minor' => 0,
    ]);
});

it('carries a negative net position forward and refreshes the cached mysql snapshot', function () {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-01-10 12:00:00 UTC');
    $instructor = User::factory()->create();
    $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
    $schedule = RevenueScheduleItem::factory()->create(['payment_id' => $payment->id]);
    ledgerEntry($instructor, $payment, $schedule, 100, LedgerEntryType::Earning, 'earning-negative');
    ledgerEntry($instructor, $payment, $schedule, -300, LedgerEntryType::RefundAdjustment, 'refund-negative');

    $snapshot = app(RefreshInstructorBalanceSnapshot::class)->handle($instructor->id, 'EGP');

    expect($snapshot->earned_minor)->toBe(100)
        ->and($snapshot->adjusted_minor)->toBe(-300)
        ->and($snapshot->outstanding_minor)->toBe(0)
        ->and(Cache::get("balance:{$instructor->id}:EGP")['deficit_minor'])->toBe(200);
});

function ledgerEntry(
    User $instructor,
    SubscriptionPayment $payment,
    RevenueScheduleItem $schedule,
    int $amount,
    LedgerEntryType $type,
    string $sourceKey,
    string $earnedAt = '2026-01-01 00:00:00',
): InstructorLedgerEntry {
    return InstructorLedgerEntry::factory()->create([
        'instructor_id' => $instructor->id,
        'payment_id' => $payment->id,
        'schedule_item_id' => $schedule->id,
        'type' => $type,
        'amount_minor' => $amount,
        'currency' => 'EGP',
        'earned_at' => $earnedAt,
        'source_key' => $sourceKey,
    ]);
}
