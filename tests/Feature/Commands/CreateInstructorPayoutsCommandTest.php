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

beforeEach(function () {
    Queue::fake();
    Cache::flush();
    config(['ledger.payout_thresholds.EGP' => 0]);
    $this->payoutLedgerEntry = function (User $instructor, int $amount, string $sourceKey) {
        $payment = SubscriptionPayment::factory()->create(['currency' => 'EGP']);
        $schedule = RevenueScheduleItem::factory()->create(['payment_id' => $payment->id]);

        return InstructorLedgerEntry::factory()->create([
            'instructor_id' => $instructor->id,
            'payment_id' => $payment->id,
            'schedule_item_id' => $schedule->id,
            'type' => LedgerEntryType::Earning,
            'amount_minor' => $amount,
            'currency' => 'EGP',
            'earned_at' => '2026-01-01 00:00:00',
            'source_key' => $sourceKey,
        ]);
    };
});

it('reports a dry run without writes or dispatch', function () {
    $instructor = User::factory()->create();
    ($this->payoutLedgerEntry)($instructor, 500, 'dry-run-earning');

    $this->artisan('instructors:payout --currency=EGP --limit=1000 --dry-run')
        ->expectsOutputToContain('1 candidate')
        ->assertSuccessful();

    expect(Payout::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('processes candidates deterministically and repeated runs do not duplicate claims', function () {
    $instructors = User::factory()->count(2)->create()->sortBy('id')->values();
    ($this->payoutLedgerEntry)($instructors[1], 500, 'command-second');
    ($this->payoutLedgerEntry)($instructors[0], 500, 'command-first');

    $this->artisan('instructors:payout --currency=EGP --limit=1')->assertSuccessful();
    expect(Payout::query()->sole()->instructor_id)->toBe($instructors[0]->id);

    $this->artisan('instructors:payout --currency=EGP --limit=1000')->assertSuccessful();
    $this->artisan('instructors:payout --currency=EGP --limit=1000')->assertSuccessful();

    expect(Payout::query()->count())->toBe(2);
    Queue::assertPushed(ProcessInstructorPayout::class, 2);
});

it('uses an owner-safe discovery lock as an optimization', function () {
    $instructor = User::factory()->create();
    ($this->payoutLedgerEntry)($instructor, 500, 'locked-earning');
    $lock = Cache::lock('payout-discovery:EGP', 30);
    expect($lock->get())->toBeTrue();

    $this->artisan('instructors:payout --currency=EGP')->expectsOutputToContain('already running')->assertSuccessful();
    expect(Payout::query()->count())->toBe(0);

    $lock->release();
    $this->artisan('instructors:payout --currency=EGP')->assertSuccessful();
    expect(Payout::query()->count())->toBe(1);
});
