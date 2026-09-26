<?php

use App\Models\InstructorLedgerEntry;
use App\Models\PaymentInstructorShare;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\Refund;
use App\Models\RevenueScheduleItem;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function makeFinancialGraph(): array
{
    $student = User::factory()->create();
    $instructor = User::factory()->create();
    $subscription = Subscription::factory()->for($student, 'student')->create();
    $payment = SubscriptionPayment::factory()->for($subscription)->create();
    $schedule = RevenueScheduleItem::factory()->for($payment, 'payment')->create();
    $ledger = InstructorLedgerEntry::factory()
        ->for($instructor, 'instructor')
        ->for($payment, 'payment')
        ->for($schedule, 'scheduleItem')
        ->create();

    return compact('student', 'instructor', 'subscription', 'payment', 'schedule', 'ledger');
}

it('creates the financial tables and selective indexes', function () {
    foreach ([
        'subscriptions',
        'subscription_payments',
        'payment_instructor_shares',
        'revenue_schedule_items',
        'instructor_ledger_entries',
        'refunds',
        'refund_allocations',
        'payouts',
        'payout_items',
        'payout_attempts',
        'instructor_balance_snapshots',
        'idempotency_records',
        'mock_provider_transfers',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    $indexes = collect(DB::select('SHOW INDEX FROM instructor_ledger_entries'))
        ->pluck('Key_name');

    expect($indexes)->toContain('ledger_instructor_currency_earned_idx')
        ->and($indexes)->toContain('ledger_type_earned_idx');
});

it('rejects duplicate payment provider references', function () {
    $payment = makeFinancialGraph()['payment'];

    expect(fn () => SubscriptionPayment::factory()->create([
        'provider_reference' => $payment->provider_reference,
    ]))->toThrow(QueryException::class);
});

it('enforces positive payment amounts valid basis points and valid terms', function (array $attributes) {
    expect(fn () => SubscriptionPayment::factory()->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'zero amount' => [['amount_minor' => 0]],
    'negative amount' => [['amount_minor' => -1]],
    'negative basis points' => [['platform_bps' => -1]],
    'basis points above one hundred percent' => [['platform_bps' => 10001]],
    'empty term' => [['term_start' => '2026-01-02', 'term_end' => '2026-01-02']],
    'reversed term' => [['term_start' => '2026-01-03', 'term_end' => '2026-01-02']],
]);

it('rejects duplicate instructors and non-positive weights per payment', function () {
    $graph = makeFinancialGraph();
    $share = PaymentInstructorShare::factory()->create([
        'payment_id' => $graph['payment']->id,
        'instructor_id' => $graph['instructor']->id,
    ]);

    expect(fn () => PaymentInstructorShare::factory()->create([
        'payment_id' => $share->payment_id,
        'instructor_id' => $share->instructor_id,
    ]))->toThrow(QueryException::class)
        ->and(fn () => PaymentInstructorShare::factory()->create(['weight' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects duplicate ledger and refund source identities', function () {
    $graph = makeFinancialGraph();
    $refund = Refund::factory()->for($graph['payment'], 'payment')->create();

    expect(fn () => InstructorLedgerEntry::factory()->create([
        'source_key' => $graph['ledger']->source_key,
    ]))->toThrow(QueryException::class)
        ->and(fn () => Refund::factory()->create([
            'provider_reference' => $refund->provider_reference,
        ]))->toThrow(QueryException::class);
});

it('rejects duplicate payout idempotency keys', function () {
    $payout = Payout::factory()->create();

    expect(fn () => Payout::factory()->create([
        'idempotency_key' => $payout->idempotency_key,
    ]))->toThrow(QueryException::class);
});

it('allows one active reservation and reclaims only after confirmed release', function () {
    $graph = makeFinancialGraph();
    $first = PayoutItem::factory()->create([
        'ledger_entry_id' => $graph['ledger']->id,
    ]);

    expect(fn () => PayoutItem::factory()->create([
        'ledger_entry_id' => $graph['ledger']->id,
    ]))->toThrow(QueryException::class);

    $first->update(['released_at' => now()]);
    $second = PayoutItem::factory()->create([
        'ledger_entry_id' => $graph['ledger']->id,
    ]);

    expect($first->fresh()->exists)->toBeTrue()
        ->and($second->ledger_entry_id)->toBe($graph['ledger']->id);
});

it('restricts deletion of referenced financial records', function () {
    $payment = makeFinancialGraph()['payment'];

    expect(fn () => $payment->delete())->toThrow(QueryException::class);
});
