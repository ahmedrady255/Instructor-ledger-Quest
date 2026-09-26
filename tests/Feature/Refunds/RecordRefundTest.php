<?php

use App\Application\Ledger\CalculateInstructorBalance;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Payouts\PayoutStatus;
use App\Jobs\RecognizeRevenue;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

it('appends mid-term cancellations and recognized adjustments without changing original facts', function () {
    [$paymentId] = refundablePayment();
    CarbonImmutable::setTestNow('2026-01-02 12:00:00 UTC');
    (new RecognizeRevenue($paymentId))->handle();
    $before = [
        DB::table('subscription_payments')->count(),
        DB::table('revenue_schedule_items')->count(),
        DB::table('instructor_ledger_entries')->where('type', LedgerEntryType::Earning->value)->count(),
    ];

    $response = $this->withHeader('Idempotency-Key', 'refund-full')
        ->postJson('/api/refunds', [
            'payment_id' => $paymentId,
            'provider_reference' => 'refund_001',
            'amount_minor' => 1000,
            'effective_at' => '2026-01-03T00:00:00Z',
            'reason' => 'cancelled',
        ])->assertCreated();

    expect($response->json('data.amount_minor'))->toBe(1000)
        ->and((int) DB::table('refund_allocations')->sum('amount_minor'))->toBe(800)
        ->and((int) DB::table('refund_allocations')->where('kind', 'FUTURE_CANCELLATION')->sum('amount_minor'))->toBe(400)
        ->and((int) DB::table('refund_allocations')->where('kind', 'RECOGNIZED_ADJUSTMENT')->sum('amount_minor'))->toBe(400)
        ->and((int) DB::table('instructor_ledger_entries')->where('type', LedgerEntryType::RefundAdjustment->value)->sum('amount_minor'))->toBe(-400)
        ->and([
            DB::table('subscription_payments')->count(),
            DB::table('revenue_schedule_items')->count(),
            DB::table('instructor_ledger_entries')->where('type', LedgerEntryType::Earning->value)->count(),
        ])->toBe($before);
});

it('handles repeated partial refunds idempotently and rejects cumulative overpayment', function () {
    [$paymentId] = refundablePayment();
    $payload = fn (string $reference, int $amount) => [
        'payment_id' => $paymentId,
        'provider_reference' => $reference,
        'amount_minor' => $amount,
        'effective_at' => '2025-12-31T00:00:00Z',
        'reason' => 'partial',
    ];

    $first = $this->withHeader('Idempotency-Key', 'refund-part-1')->postJson('/api/refunds', $payload('refund_002', 400));
    $replay = $this->withHeader('Idempotency-Key', 'refund-part-1')->postJson('/api/refunds', $payload('refund_002', 400));
    $this->withHeader('Idempotency-Key', 'refund-part-2')->postJson('/api/refunds', $payload('refund_003', 600))->assertCreated();
    $this->withHeader('Idempotency-Key', 'refund-too-much')->postJson('/api/refunds', $payload('refund_004', 1))->assertConflict();

    expect($first->status())->toBe(201)
        ->and($replay->json())->toEqual($first->json())
        ->and(DB::table('refunds')->count())->toBe(2)
        ->and((int) DB::table('refund_allocations')->sum('amount_minor'))->toBe(800)
        ->and(DB::table('instructor_ledger_entries')->where('type', LedgerEntryType::RefundAdjustment->value)->count())->toBe(0);
});

it('validates refund input and conflicting provider references', function () {
    [$paymentId] = refundablePayment();
    $payload = [
        'payment_id' => $paymentId,
        'provider_reference' => 'refund_005',
        'amount_minor' => 100,
        'effective_at' => '2026-01-03T00:00:00Z',
    ];

    $this->withHeader('Idempotency-Key', 'refund-valid')->postJson('/api/refunds', $payload)->assertCreated();
    $this->withHeader('Idempotency-Key', 'refund-conflict')->postJson('/api/refunds', [...$payload, 'amount_minor' => 101])->assertConflict();
    $this->withHeader('Idempotency-Key', 'refund-invalid')->postJson('/api/refunds', [...$payload, 'provider_reference' => 'refund_006', 'amount_minor' => 0])->assertUnprocessable();
});

it('turns an after-term refund into adjustments and carries an already-paid deficit', function () {
    [$paymentId, $instructors] = refundablePayment();
    CarbonImmutable::setTestNow('2026-01-05 12:00:00 UTC');
    (new RecognizeRevenue($paymentId))->handle();
    $earning = InstructorLedgerEntry::query()->where('instructor_id', $instructors[0]->id)->firstOrFail();
    $payout = Payout::factory()->create([
        'instructor_id' => $instructors[0]->id,
        'currency' => 'EGP',
        'status' => PayoutStatus::Succeeded,
        'amount_minor' => 100,
        'completed_at' => '2026-01-05 13:00:00',
    ]);
    PayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'ledger_entry_id' => $earning->id,
        'amount_minor' => 100,
    ]);
    CarbonImmutable::setTestNow('2026-01-06 12:00:00 UTC');

    $this->withHeader('Idempotency-Key', 'refund-after-term')->postJson('/api/refunds', [
        'payment_id' => $paymentId,
        'provider_reference' => 'refund_after_term',
        'amount_minor' => 1000,
        'effective_at' => '2026-01-06T00:00:00Z',
    ])->assertCreated();

    $balance = app(CalculateInstructorBalance::class)->for($instructors[0]->id, 'EGP', CarbonImmutable::now('UTC'));
    expect(DB::table('refund_allocations')->where('kind', 'FUTURE_CANCELLATION')->count())->toBe(0)
        ->and((int) DB::table('refund_allocations')->where('kind', 'RECOGNIZED_ADJUSTMENT')->sum('amount_minor'))->toBe(800)
        ->and($balance['deficit_minor'])->toBe(100)
        ->and(Payout::query()->find($payout->id)->status)->toBe(PayoutStatus::Succeeded);
});

function refundablePayment(): array
{
    $subscription = Subscription::factory()->create();
    $instructors = User::factory()->count(2)->create()->sortBy('id')->values();
    $response = test()->withHeader('Idempotency-Key', fake()->uuid())->postJson('/api/subscription-payments', [
        'provider_reference' => fake()->unique()->uuid(),
        'subscription_id' => $subscription->id,
        'amount_minor' => 1000,
        'currency' => 'EGP',
        'paid_at' => '2026-01-01T00:00:00Z',
        'term_start' => '2026-01-01T00:00:00Z',
        'term_end' => '2026-01-05T00:00:00Z',
        'platform_bps' => 2000,
        'instructors' => [
            ['instructor_id' => $instructors[0]->id, 'weight' => 1],
            ['instructor_id' => $instructors[1]->id, 'weight' => 1],
        ],
    ])->assertCreated();

    return [$response->json('data.id'), $instructors];
}
