<?php

namespace Database\Factories;

use App\Models\RevenueScheduleItem;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class RevenueScheduleItemFactory extends Factory
{
    protected $model = RevenueScheduleItem::class;

    public function definition(): array
    {
        return [
            'payment_id' => SubscriptionPayment::factory(),
            'service_date' => '2026-01-01',
            'instructor_pool_minor' => 1000,
            'status' => 'PENDING',
            'recognized_at' => null,
        ];
    }
}
