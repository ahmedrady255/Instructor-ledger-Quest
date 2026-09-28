<?php

use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Payouts\PayoutStatus;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ViewInstructorFinancialSummary;
use App\Filament\Resources\UserResource\RelationManagers\PayoutsRelationManager;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('requires an authorized panel user', function () {
    $instructor = User::factory()->create();

    $this->get(UserResource::getUrl('view', ['record' => $instructor]))
        ->assertRedirect('/admin/login');

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)
        ->get(UserResource::getUrl('view', ['record' => $instructor]))
        ->assertForbidden();
});

it('shows exact per-currency balances and safe paginated payout history read only', function () {
    $admin = User::factory()->create();
    $instructor = User::factory()->create(['name' => 'Instructor One']);
    DB::table('instructor_balance_snapshots')->insert([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'earned_minor' => 1234,
        'adjusted_minor' => -34,
        'paid_minor' => 200,
        'reserved_minor' => 100,
        'outstanding_minor' => 900,
        'as_of' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Payout::factory()->count(30)
        ->sequence(fn (Sequence $sequence) => ['created_at' => now()->subDays($sequence->index)])
        ->create([
            'instructor_id' => $instructor->id,
            'currency' => 'EGP',
            'status' => PayoutStatus::Pending,
            'destination_snapshot' => ['token' => 'never-render-this-secret'],
        ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($admin)->get(UserResource::getUrl('view', ['record' => $instructor]));
    $queryCount = count(DB::getQueryLog());

    $response->assertOk()
        ->assertSee('Instructor One')
        ->assertSee('EGP')
        ->assertSee('12.34')
        ->assertSee('-0.34')
        ->assertSee('9.00')
        ->assertDontSee('never-render-this-secret')
        ->assertDontSee('Create')
        ->assertDontSee('Edit')
        ->assertDontSee('Delete');
    expect($queryCount)->toBeLessThan(25);

    $firstPage = Payout::query()->where('instructor_id', $instructor->id)->latest()->limit(10)->get();
    $later = Payout::query()->where('instructor_id', $instructor->id)->oldest()->firstOrFail();
    Livewire::test(PayoutsRelationManager::class, [
        'ownerRecord' => $instructor,
        'pageClass' => ViewInstructorFinancialSummary::class,
    ])->assertCanSeeTableRecords($firstPage)
        ->assertCanNotSeeTableRecords([$later])
        ->assertSee('PENDING')
        ->assertDontSee('never-render-this-secret')
        ->assertTableFilterExists('status')
        ->assertTableFilterExists('currency')
        ->assertTableFilterExists('created_at')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('shows the global ledger as a read only paginated table', function () {
    $admin = User::factory()->create();
    $entry = InstructorLedgerEntry::factory()->create([
        'source_key' => 'global-ledger-entry',
        'type' => LedgerEntryType::RefundAdjustment,
        'amount_minor' => -1234,
        'currency' => 'EGP',
    ]);

    $this->actingAs($admin)
        ->get('/admin/instructor-ledger-entries')
        ->assertOk()
        ->assertSee($entry->instructor->name)
        ->assertSee('REFUND_ADJUSTMENT')
        ->assertSee('-12.34 EGP')
        ->assertSee('global-ledger-entry')
        ->assertDontSee('Create')
        ->assertDontSee('Edit')
        ->assertDontSee('Delete');
});

it('shows only the selected instructor ledger entries in a read only tab', function () {
    $component = 'App\\Filament\\Resources\\UserResource\\RelationManagers\\LedgerEntriesRelationManager';
    expect(class_exists($component))->toBeTrue();

    $admin = User::factory()->create();
    $instructor = User::factory()->create();
    $entries = InstructorLedgerEntry::factory()->count(30)
        ->sequence(fn (Sequence $sequence) => [
            'earned_at' => now()->subDays($sequence->index),
            'source_key' => "instructor-entry-{$sequence->index}",
        ])
        ->create(['instructor_id' => $instructor->id]);
    $outsider = InstructorLedgerEntry::factory()->create(['source_key' => 'other-instructor-entry']);

    Livewire::test($component, [
        'ownerRecord' => $instructor,
        'pageClass' => ViewInstructorFinancialSummary::class,
    ])->assertCanSeeTableRecords($entries->take(10))
        ->assertCanNotSeeTableRecords($entries->skip(10))
        ->assertCanNotSeeTableRecords([$outsider])
        ->assertSee('instructor-entry-0')
        ->assertTableFilterExists('type')
        ->assertTableFilterExists('currency')
        ->assertTableFilterExists('earned_at')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});
