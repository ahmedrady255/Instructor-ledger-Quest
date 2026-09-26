<?php

use App\Jobs\RecognizeRevenue;
use App\Models\InstructorLedgerEntry;
use App\Models\PaymentInstructorShare;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recognizes due schedule rows once and leaves future rows pending', function () {
    CarbonImmutable::setTestNow('2026-01-02 12:00:00 UTC');
    $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
    $instructors = User::factory()->count(2)->create()->sortBy('id')->values();

    PaymentInstructorShare::factory()->create([
        'payment_id' => $payment->id,
        'instructor_id' => $instructors[0]->id,
        'weight' => 2,
        'stable_order' => 0,
    ]);
    PaymentInstructorShare::factory()->create([
        'payment_id' => $payment->id,
        'instructor_id' => $instructors[1]->id,
        'weight' => 1,
        'stable_order' => 1,
    ]);

    $due = RevenueScheduleItem::factory()->create([
        'payment_id' => $payment->id,
        'service_date' => '2026-01-02',
        'instructor_pool_minor' => 100,
    ]);
    $future = RevenueScheduleItem::factory()->create([
        'payment_id' => $payment->id,
        'service_date' => '2026-01-03',
        'instructor_pool_minor' => 100,
    ]);
    $refund = Refund::factory()->create(['payment_id' => $payment->id]);
    RefundAllocation::query()->create([
        'refund_id' => $refund->id,
        'schedule_item_id' => $due->id,
        'instructor_id' => $instructors[0]->id,
        'kind' => 'FUTURE_CANCELLATION',
        'amount_minor' => 7,
        'source_key' => 'refund-cancel-1',
    ]);

    (new RecognizeRevenue($payment->id))->handle();
    (new RecognizeRevenue($payment->id))->handle();

    expect($payment->fresh()->scheduleItems()->find($due->id)->status)->toBe('RECOGNIZED')
        ->and($payment->fresh()->scheduleItems()->find($future->id)->status)->toBe('PENDING')
        ->and($due->fresh()->recognized_at)->not->toBeNull()
        ->and($payment->fresh()->scheduleItems()->whereKey($future->id)->first()->recognized_at)->toBeNull();

    $entries = $payment->fresh()->hasMany(InstructorLedgerEntry::class, 'payment_id')
        ->orderBy('instructor_id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('amount_minor')->all())->toBe([60, 33])
        ->and($entries->pluck('source_key')->unique())->toHaveCount(2);
});
