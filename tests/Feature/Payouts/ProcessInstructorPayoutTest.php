<?php

use App\Domain\Payouts\PayoutStatus;
use App\Infrastructure\Payments\PaymentProvider;
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
    config(['ledger.mock_provider_outcome' => 'success']);
});

it('submits once and ignores duplicate delivery after success', function () {
    $payout = executablePayout();

    (new ProcessInstructorPayout($payout->id))->handle();
    (new ProcessInstructorPayout($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Succeeded)
        ->and(MockProviderTransfer::query()->count())->toBe(1)
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1)
        ->and($payout->attempts()->count())->toBe(1)
        ->and($payout->attempts()->sole()->response_payload)->toBe(['outcome' => 'SUCCEEDED'])
        ->and(json_encode($payout->attempts()->sole()->response_payload))->not->toContain('secret-token');
});

it('releases reservations only after a confirmed permanent failure', function () {
    config(['ledger.mock_provider_outcome' => 'permanent_failure']);
    $payout = executablePayout();

    (new ProcessInstructorPayout($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::PermanentlyFailed)
        ->and($payout->items()->sole()->released_at)->not->toBeNull()
        ->and(MockProviderTransfer::query()->count())->toBe(1);
});

it('keeps reservations and reconciles timeout after provider success without resubmitting', function () {
    config(['ledger.mock_provider_outcome' => 'timeout_after_success']);
    $payout = executablePayout();

    (new ProcessInstructorPayout($payout->id))->handle();
    (new ProcessInstructorPayout($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Unknown)
        ->and($payout->items()->sole()->released_at)->toBeNull()
        ->and(MockProviderTransfer::query()->sole()->status->value)->toBe('SUCCEEDED')
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1);
    Queue::assertPushed(CheckPayoutStatus::class);
});

it('returns the existing provider result for repeated submits with the same key', function () {
    $provider = app(PaymentProvider::class);

    $first = $provider->submitPayout('provider-key', 500, 'EGP', ['token' => 'secret-token']);
    $second = $provider->submitPayout('provider-key', 500, 'EGP', ['token' => 'secret-token']);

    expect($second)->toEqual($first)
        ->and(MockProviderTransfer::query()->count())->toBe(1)
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(2);
});

it('reconciles a processing crash window when provider state already exists', function () {
    $payout = executablePayout(['status' => PayoutStatus::Processing]);
    app(PaymentProvider::class)->submitPayout(
        $payout->idempotency_key,
        $payout->amount_minor,
        $payout->currency,
        $payout->destination_snapshot,
    );

    (new ProcessInstructorPayout($payout->id))->handle();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1);
    Queue::assertPushed(CheckPayoutStatus::class, fn ($job) => $job->payoutId === $payout->id);
});

function executablePayout(array $overrides = []): Payout
{
    $entry = InstructorLedgerEntry::factory()->create(['amount_minor' => 500, 'currency' => 'EGP']);
    $payout = Payout::factory()->create([
        'instructor_id' => $entry->instructor_id,
        'amount_minor' => 500,
        'currency' => 'EGP',
        'destination_snapshot' => ['token' => 'secret-token'],
        ...$overrides,
    ]);
    PayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'ledger_entry_id' => $entry->id,
        'amount_minor' => 500,
    ]);

    return $payout;
}
