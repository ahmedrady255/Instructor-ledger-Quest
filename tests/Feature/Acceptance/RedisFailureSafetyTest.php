<?php

use App\Domain\Ledger\LedgerEntryType;
use App\Jobs\ProcessInstructorPayout;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('uses mysql authority when the redis discovery lock and balance cache fail', function () {
    Queue::fake();
    config(['ledger.payout_thresholds.EGP' => 0]);
    $instructor = User::factory()->create();
    $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
    $schedule = RevenueScheduleItem::factory()->create(['payment_id' => $payment->id]);
    InstructorLedgerEntry::factory()->create([
        'instructor_id' => $instructor->id,
        'payment_id' => $payment->id,
        'schedule_item_id' => $schedule->id,
        'type' => LedgerEntryType::Earning,
        'amount_minor' => 500,
        'currency' => 'EGP',
        'earned_at' => now()->subDay(),
        'source_key' => 'redis-failure-earning',
    ]);
    app('cache.store');
    Cache::shouldReceive('lock')->once()->andThrow(new RuntimeException('redis unavailable'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis unavailable'));

    $this->artisan('instructors:payout --currency=EGP')->assertSuccessful();

    expect(Payout::query()->count())->toBe(1)
        ->and(Payout::query()->sole()->items()->count())->toBe(1);
    Queue::assertPushed(ProcessInstructorPayout::class, 1);
});
