<?php

namespace Database\Factories;

use App\Domain\Payouts\PayoutAttemptKind;
use App\Domain\Payouts\ProviderOutcome;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutAttemptFactory extends Factory
{
    protected $model = PayoutAttempt::class;

    public function definition(): array
    {
        return [
            'payout_id' => Payout::factory(),
            'kind' => PayoutAttemptKind::Submission,
            'attempt_no' => 1,
            'status' => ProviderOutcome::Pending,
            'request_id' => fake()->uuid(),
            'response_code' => null,
            'response_payload' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }
}
