<?php

namespace Database\Factories;

use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use App\Models\PayoutItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutItemFactory extends Factory
{
    protected $model = PayoutItem::class;

    public function definition(): array
    {
        return [
            'payout_id' => Payout::factory(),
            'ledger_entry_id' => InstructorLedgerEntry::factory(),
            'amount_minor' => 1000,
            'released_at' => null,
        ];
    }
}
