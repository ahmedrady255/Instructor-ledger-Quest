<?php

namespace Database\Factories;

use App\Models\Refund;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'payment_id' => SubscriptionPayment::factory(),
            'provider_reference' => fake()->unique()->uuid(),
            'amount_minor' => 1000,
            'effective_at' => '2026-06-01 00:00:00',
            'reason' => 'requested',
        ];
    }
}
