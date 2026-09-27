<?php

namespace Database\Seeders;

use App\Application\Payments\RecordSubscriptionPayment;
use App\Jobs\RecognizeRevenue;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InstructorLedgerDemoSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'Demo Admin', 'email_verified_at' => now(), 'password' => Hash::make('password')],
        );
        $student = User::query()->updateOrCreate(
            ['email' => 'student@example.test'],
            ['name' => 'Demo Student', 'email_verified_at' => now(), 'password' => Hash::make('password')],
        );
        $instructors = collect([
            ['email' => 'ada@example.test', 'name' => 'Ada Instructor'],
            ['email' => 'grace@example.test', 'name' => 'Grace Instructor'],
        ])->map(fn (array $user) => User::query()->updateOrCreate(
            ['email' => $user['email']],
            [...$user, 'email_verified_at' => now(), 'password' => Hash::make('password')],
        ));
        $subscription = Subscription::query()->updateOrCreate(
            ['student_id' => $student->id, 'plan' => 'demo'],
            [
                'starts_at' => '2026-01-01 00:00:00',
                'ends_at' => '2026-01-05 00:00:00',
                'status' => 'ACTIVE',
            ],
        );
        $payment = app(RecordSubscriptionPayment::class)->handle([
            'provider_reference' => 'demo_payment_001',
            'subscription_id' => $subscription->id,
            'amount_minor' => 100_000,
            'currency' => 'EGP',
            'paid_at' => '2026-01-01T00:00:00Z',
            'term_start' => '2026-01-01T00:00:00Z',
            'term_end' => '2026-01-05T00:00:00Z',
            'platform_bps' => 2000,
            'instructors' => [
                ['instructor_id' => $instructors[0]->id, 'weight' => 2],
                ['instructor_id' => $instructors[1]->id, 'weight' => 1],
            ],
        ]);

        (new RecognizeRevenue($payment->id))->handle();
    }
}
