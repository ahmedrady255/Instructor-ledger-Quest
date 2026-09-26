<?php

use App\Application\Payouts\CreateInstructorPayout;
use App\Domain\Ledger\LedgerEntryType;
use App\Jobs\ProcessInstructorPayout;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['ledger.payout_thresholds.EGP' => 0]);
    $this->payoutLedgerEntry = function (User $instructor, int $amount, string $sourceKey, LedgerEntryType $type = LedgerEntryType::Earning) {
        $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
        $schedule = RevenueScheduleItem::factory()->create(['payment_id' => $payment->id]);

        return InstructorLedgerEntry::factory()->create([
            'instructor_id' => $instructor->id,
            'payment_id' => $payment->id,
            'schedule_item_id' => $schedule->id,
            'type' => $type,
            'amount_minor' => $amount,
            'currency' => 'EGP',
            'earned_at' => '2026-01-01 00:00:00',
            'source_key' => $sourceKey,
        ]);
    };
});

it('reserves deterministic whole entries without exceeding available balance', function () {
    $instructor = User::factory()->create();
    ($this->payoutLedgerEntry)($instructor, 400, 'earning-400');
    ($this->payoutLedgerEntry)($instructor, 300, 'earning-300');
    ($this->payoutLedgerEntry)($instructor, 200, 'earning-200');
    ($this->payoutLedgerEntry)($instructor, -300, 'adjustment', LedgerEntryType::RefundAdjustment);

    $payout = app(CreateInstructorPayout::class)->handle($instructor->id, 'EGP');

    expect($payout)->toBeInstanceOf(Payout::class)
        ->and($payout->amount_minor)->toBe(600)
        ->and($payout->items()->orderBy('ledger_entry_id')->pluck('amount_minor')->all())->toBe([400, 200])
        ->and(app(CreateInstructorPayout::class)->handle($instructor->id, 'EGP'))->toBeNull();
    Queue::assertPushed(ProcessInstructorPayout::class, fn ($job) => $job->payoutId === $payout->id);
});

it('honours currency thresholds and negative carry-forward balances', function () {
    $belowThreshold = User::factory()->create();
    ($this->payoutLedgerEntry)($belowThreshold, 500, 'threshold-earning');
    config(['ledger.payout_thresholds.EGP' => 501]);

    expect(app(CreateInstructorPayout::class)->handle($belowThreshold->id, 'EGP'))->toBeNull();

    config(['ledger.payout_thresholds.EGP' => 0]);
    $negative = User::factory()->create();
    ($this->payoutLedgerEntry)($negative, 100, 'negative-earning');
    ($this->payoutLedgerEntry)($negative, -200, 'negative-adjustment', LedgerEntryType::RefundAdjustment);

    expect(app(CreateInstructorPayout::class)->handle($negative->id, 'EGP'))->toBeNull()
        ->and(Payout::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
