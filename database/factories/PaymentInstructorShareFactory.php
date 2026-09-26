<?php

namespace Database\Factories;

use App\Models\PaymentInstructorShare;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentInstructorShareFactory extends Factory
{
    protected $model = PaymentInstructorShare::class;

    public function definition(): array
    {
        return [
            'payment_id' => SubscriptionPayment::factory(),
            'instructor_id' => User::factory(),
            'weight' => 1,
            'stable_order' => 0,
        ];
    }
}
