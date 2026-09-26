<?php

use App\Domain\Payouts\PayoutStatus;
use App\Domain\Payouts\ProviderOutcome;
use App\Jobs\CheckPayoutStatus;
use App\Jobs\ProcessInstructorPayout;
use App\Models\InstructorLedgerEntry;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use App\Models\PayoutItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['ledger.manual_review_after_minutes' => 60]);
    $this->uncertainPayout = function (PayoutStatus $status = PayoutStatus::Unknown, string $createdAt = '2026-01-01 00:00:00') {
        $entry = InstructorLedgerEntry::factory()->create(['amount_minor' => 500, 'currency' => 'EGP']);
        $payout = Payout::factory()->create([
            'instructor_id' => $entry->instructor_id,
            'amount_minor' => 500,
            'currency' => 'EGP',
            'status' => $status,
            'destination_snapshot' => ['token' => 'secret-token'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        PayoutItem::factory()->create([
            'payout_id' => $payout->id,
            'ledger_entry_id' => $entry->id,
            'amount_minor' => 500,
        ]);

        return $payout;
    };
});

it('converges timeout after success by status lookup without resubmission', function () {
    config(['ledger.mock_provider_outcome' => 'timeout_after_success']);
    $payout = ($this->uncertainPayout)(PayoutStatus::Pending);
    (new ProcessInstructorPayout($payout->id))->handle();

    (new CheckPayoutStatus($payout->id))->handle();
    (new CheckPayoutStatus($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Succeeded)
        ->and(MockProviderTransfer::query()->count())->toBe(1)
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1)
        ->and($payout->fresh()->reconciliation_count)->toBe(1)
        ->and($payout->attempts()->where('kind', 'RECONCILIATION')->count())->toBe(1);
});

it('releases a reservation only when status confirms permanent failure', function () {
    $payout = ($this->uncertainPayout)();
    providerState($payout, ProviderOutcome::PermanentlyFailed);

    (new CheckPayoutStatus($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::PermanentlyFailed)
        ->and($payout->items()->sole()->released_at)->not->toBeNull();
});

it('retains unresolved reservations and flags old payouts for manual review', function (ProviderOutcome $outcome) {
    $payout = ($this->uncertainPayout)(PayoutStatus::Unknown, '2025-01-01 00:00:00');
    if ($outcome !== ProviderOutcome::NotFound) {
        providerState($payout, $outcome);
    }

    (new CheckPayoutStatus($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Unknown)
        ->and($payout->items()->sole()->released_at)->toBeNull()
        ->and($payout->fresh()->manual_review_at)->not->toBeNull()
        ->and($payout->fresh()->reconciliation_count)->toBe(1);
})->with([
    ProviderOutcome::Pending,
    ProviderOutcome::Unknown,
    ProviderOutcome::NotFound,
]);

function providerState(Payout $payout, ProviderOutcome $outcome): void
{
    MockProviderTransfer::query()->create([
        'idempotency_key' => $payout->idempotency_key,
        'amount_minor' => $payout->amount_minor,
        'currency' => $payout->currency,
        'destination_snapshot' => $payout->destination_snapshot,
        'status' => $outcome,
        'provider_reference' => $outcome === ProviderOutcome::Succeeded ? 'mock_success' : null,
        'submission_count' => 1,
    ]);
}
