<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'provider_reference' => fake()->unique()->uuid(),
            'amount_minor' => 120_000,
            'currency' => 'EGP',
            'paid_at' => '2026-01-01 00:00:00',
            'platform_bps' => 2000,
            'term_start' => '2026-01-01 00:00:00',
            'term_end' => '2027-01-01 00:00:00',
        ];
    }
}
