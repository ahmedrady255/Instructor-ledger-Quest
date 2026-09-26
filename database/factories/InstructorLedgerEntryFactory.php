<?php

namespace Database\Factories;

use App\Domain\Ledger\LedgerEntryType;
use App\Models\InstructorLedgerEntry;
use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstructorLedgerEntryFactory extends Factory
{
    protected $model = InstructorLedgerEntry::class;

    public function definition(): array
    {
        return [
            'instructor_id' => User::factory(),
            'payment_id' => SubscriptionPayment::factory(),
            'refund_id' => null,
            'schedule_item_id' => RevenueScheduleItem::factory(),
            'type' => LedgerEntryType::Earning,
            'amount_minor' => 1000,
            'currency' => 'EGP',
            'earned_at' => '2026-01-01 00:00:00',
            'source_key' => fake()->unique()->uuid(),
            'metadata' => null,
        ];
    }
}
