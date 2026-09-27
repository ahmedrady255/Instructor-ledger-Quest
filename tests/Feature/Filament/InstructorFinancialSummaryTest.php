<?php

use App\Domain\Payouts\PayoutStatus;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ViewInstructorFinancialSummary;
use App\Filament\Resources\UserResource\RelationManagers\PayoutsRelationManager;
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
