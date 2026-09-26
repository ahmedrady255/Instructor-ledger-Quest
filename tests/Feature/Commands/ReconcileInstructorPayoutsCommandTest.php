<?php

use App\Domain\Payouts\PayoutStatus;
use App\Jobs\CheckPayoutStatus;
use App\Models\Payout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

it('dispatches only bounded stale processing and unknown payout ids', function () {
    $oldProcessing = Payout::factory()->create([
        'status' => PayoutStatus::Processing,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);
    $oldUnknown = Payout::factory()->create([
        'status' => PayoutStatus::Unknown,
        'created_at' => now()->subMinutes(9),
        'updated_at' => now()->subMinutes(9),
    ]);
    Payout::factory()->create([
        'status' => PayoutStatus::Unknown,
        'created_at' => now(),
    ]);

    $this->artisan('instructors:reconcile-payouts --older-than=5m --limit=1')->assertSuccessful();
    Queue::assertPushed(CheckPayoutStatus::class, 1);
    Queue::assertPushed(CheckPayoutStatus::class, fn ($job) => $job->payoutId === $oldProcessing->id);

    Queue::fake();
    $this->artisan('instructors:reconcile-payouts --older-than=5m --limit=1000')->assertSuccessful();
    Queue::assertPushed(CheckPayoutStatus::class, 2);
    Queue::assertPushed(CheckPayoutStatus::class, fn ($job) => $job->payoutId === $oldUnknown->id);
});

it('rejects invalid age input', function () {
    $this->artisan('instructors:reconcile-payouts --older-than=soon')->assertFailed();
    Queue::assertNothingPushed();
});
