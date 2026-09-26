<?php

use App\Jobs\RecognizeRevenue;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function paymentPayload(Subscription $subscription, array $overrides = []): array
{
    $instructors = User::factory()->count(2)->create()->sortBy('id')->values();

    return array_replace([
        'provider_reference' => 'pay_001',
        'subscription_id' => $subscription->id,
        'amount_minor' => 1000,
        'currency' => 'EGP',
        'paid_at' => '2026-01-01T00:00:00Z',
        'term_start' => '2026-01-01T00:00:00Z',
        'term_end' => '2026-01-04T00:00:00Z',
        'platform_bps' => 2000,
        'instructors' => [
            ['instructor_id' => $instructors[0]->id, 'weight' => 2],
            ['instructor_id' => $instructors[1]->id, 'weight' => 1],
        ],
    ], $overrides);
}

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

it('persists immutable payment snapshots and a conserving daily schedule before dispatch', function () {
    $subscription = Subscription::factory()->create();
    $payload = paymentPayload($subscription);

    $response = $this->withHeader('Idempotency-Key', 'idem-payment-1')
        ->postJson('/api/subscription-payments', $payload)
        ->assertCreated();

    $paymentId = $response->json('data.id');

    $this->assertDatabaseHas('subscription_payments', [
        'id' => $paymentId,
        'provider_reference' => 'pay_001',
        'amount_minor' => 1000,
        'currency' => 'EGP',
        'platform_bps' => 2000,
    ]);
    expect(DB::table('payment_instructor_shares')->where('payment_id', $paymentId)->orderBy('instructor_id')->pluck('weight')->all())
        ->toBe([2, 1])
        ->and(DB::table('revenue_schedule_items')->where('payment_id', $paymentId)->orderBy('service_date')->pluck('instructor_pool_minor')->all())
        ->toBe([267, 267, 266]);

    Queue::assertPushed(RecognizeRevenue::class, fn (RecognizeRevenue $job) => $job->paymentId === $paymentId);
});

it('returns the stored response for sequential duplicate idempotency keys', function () {
    $payload = paymentPayload(Subscription::factory()->create());

    $first = $this->withHeader('Idempotency-Key', 'idem-payment-2')->postJson('/api/subscription-payments', $payload);
    $second = $this->withHeader('Idempotency-Key', 'idem-payment-2')->postJson('/api/subscription-payments', $payload);

    expect($second->status())->toBe($first->status())
        ->and($second->json())->toBe($first->json())
        ->and(DB::table('subscription_payments')->count())->toBe(1)
        ->and(DB::table('revenue_schedule_items')->count())->toBe(3);
    Queue::assertPushed(RecognizeRevenue::class, 1);
});

it('uses mysql idempotency after the redis response cache is cleared', function () {
    $payload = paymentPayload(Subscription::factory()->create());

    $first = $this->withHeader('Idempotency-Key', 'idem-payment-3')->postJson('/api/subscription-payments', $payload);
    Cache::flush();
    $second = $this->withHeader('Idempotency-Key', 'idem-payment-3')->postJson('/api/subscription-payments', $payload);

    expect($second->status())->toBe(201)
        ->and($second->json())->toEqual($first->json())
        ->and(DB::table('subscription_payments')->count())->toBe(1);
});

it('returns an existing payment for an identical provider reference with a new request key', function () {
    $payload = paymentPayload(Subscription::factory()->create());

    $first = $this->withHeader('Idempotency-Key', 'idem-payment-4a')->postJson('/api/subscription-payments', $payload);
    $second = $this->withHeader('Idempotency-Key', 'idem-payment-4b')->postJson('/api/subscription-payments', $payload);

    $first->assertCreated();
    $second->assertOk();
    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('subscription_payments')->count())->toBe(1);
    Queue::assertPushed(RecognizeRevenue::class, 1);
});

it('rejects conflicting reuse of a provider reference', function () {
    $payload = paymentPayload(Subscription::factory()->create());

    $this->withHeader('Idempotency-Key', 'idem-payment-5a')->postJson('/api/subscription-payments', $payload)->assertCreated();
    $this->withHeader('Idempotency-Key', 'idem-payment-5b')->postJson('/api/subscription-payments', [
        ...$payload,
        'amount_minor' => 1001,
    ])->assertConflict();

    expect(DB::table('subscription_payments')->count())->toBe(1);
});

it('rejects reuse of an idempotency key for a different request', function () {
    $payload = paymentPayload(Subscription::factory()->create());

    $this->withHeader('Idempotency-Key', 'idem-payment-6')->postJson('/api/subscription-payments', $payload)->assertCreated();
    $this->withHeader('Idempotency-Key', 'idem-payment-6')->postJson('/api/subscription-payments', [
        ...$payload,
        'amount_minor' => 1001,
    ])->assertConflict();

    expect(DB::table('subscription_payments')->count())->toBe(1);
});

it('requires an idempotency key', function () {
    $payload = paymentPayload(Subscription::factory()->create());

    $this->postJson('/api/subscription-payments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

it('rejects invalid payment boundaries', function (array $overrides) {
    $payload = paymentPayload(Subscription::factory()->create(), $overrides);

    $this->withHeader('Idempotency-Key', fake()->uuid())
        ->postJson('/api/subscription-payments', $payload)
        ->assertUnprocessable();
})->with([
    'reversed term' => [['term_start' => '2026-01-04T00:00:00Z', 'term_end' => '2026-01-01T00:00:00Z']],
    'basis points above maximum' => [['platform_bps' => 10_001]],
    'lowercase currency' => [['currency' => 'egp']],
    'empty instructors' => [['instructors' => []]],
    'zero weight' => [['instructors' => [['instructor_id' => 1, 'weight' => 0]]]],
    'duplicate instructor' => [['instructors' => [
        ['instructor_id' => 1, 'weight' => 1],
        ['instructor_id' => 1, 'weight' => 2],
    ]]],
]);
