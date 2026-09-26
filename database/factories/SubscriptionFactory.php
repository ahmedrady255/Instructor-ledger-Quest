<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'student_id' => User::factory(),
            'plan' => 'pro',
            'starts_at' => '2026-01-01 00:00:00',
            'ends_at' => '2027-01-01 00:00:00',
            'status' => 'ACTIVE',
        ];
    }
}
