<?php

namespace Database\Factories;

use App\Domain\Payouts\PayoutStatus;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'instructor_id' => User::factory(),
            'idempotency_key' => fake()->unique()->uuid(),
            'amount_minor' => 1000,
            'currency' => 'EGP',
            'destination_snapshot' => ['token' => 'dst_test'],
            'status' => PayoutStatus::Pending,
            'provider_reference' => null,
            'submitted_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'manual_review_at' => null,
            'reconciliation_count' => 0,
        ];
    }
}
