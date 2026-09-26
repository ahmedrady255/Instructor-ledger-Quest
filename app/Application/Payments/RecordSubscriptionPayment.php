<?php

namespace App\Application\Payments;

use App\Domain\Money\Money;
use App\Domain\Revenue\InstructorWeight;
use App\Domain\Revenue\RecognitionScheduleBuilder;
use App\Domain\Revenue\RevenueAllocator;
use App\Jobs\RecognizeRevenue;
use App\Models\SubscriptionPayment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RecordSubscriptionPayment
{
    public function __construct(
        private RevenueAllocator $allocator,
        private RecognitionScheduleBuilder $scheduleBuilder,
    ) {}

    public function handle(array $attributes): SubscriptionPayment
    {
        return DB::transaction(function () use ($attributes) {
            $payment = SubscriptionPayment::query()
                ->where('provider_reference', $attributes['provider_reference'])
                ->lockForUpdate()
                ->first();

            if ($payment) {
                return $this->assertSamePayment($payment, $attributes);
            }

            $weights = collect($attributes['instructors'])
                ->map(fn (array $share) => new InstructorWeight($share['instructor_id'], $share['weight']))
                ->all();
            $allocation = $this->allocator->allocate(
                new Money($attributes['amount_minor'], $attributes['currency']),
                $attributes['platform_bps'],
                $weights,
            );
            $start = CarbonImmutable::parse($attributes['term_start']);
            $end = CarbonImmutable::parse($attributes['term_end']);
            $schedule = $this->scheduleBuilder->build($allocation->instructorPool->minor, $start, $end);

            try {
                $payment = SubscriptionPayment::query()->create([
                    ...collect($attributes)->except('instructors')->all(),
                    'paid_at' => CarbonImmutable::parse($attributes['paid_at']),
                    'term_start' => $start,
                    'term_end' => $end,
                ]);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }

                $payment = SubscriptionPayment::query()
                    ->where('provider_reference', $attributes['provider_reference'])
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->assertSamePayment($payment, $attributes);
            }

            collect($attributes['instructors'])
                ->sortBy('instructor_id')
                ->values()
                ->each(fn (array $share, int $index) => $payment->instructorShares()->create([
                    ...$share,
                    'stable_order' => $index,
                ]));

            $now = now();
            $payment->scheduleItems()->insert(array_map(fn ($day) => [
                'payment_id' => $payment->id,
                'service_date' => $day->date->toDateString(),
                'instructor_pool_minor' => $day->instructorPoolMinor,
                'status' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ], $schedule));

            RecognizeRevenue::dispatch($payment->id)->afterCommit();

            return $payment;
        });
    }

    private function assertSamePayment(SubscriptionPayment $payment, array $attributes): SubscriptionPayment
    {
        $same = $payment->subscription_id === $attributes['subscription_id']
            && $payment->amount_minor === $attributes['amount_minor']
            && $payment->currency === $attributes['currency']
            && $payment->platform_bps === $attributes['platform_bps']
            && $payment->paid_at->equalTo(CarbonImmutable::parse($attributes['paid_at']))
            && $payment->term_start->equalTo(CarbonImmutable::parse($attributes['term_start']))
            && $payment->term_end->equalTo(CarbonImmutable::parse($attributes['term_end']))
            && $payment->instructorShares()->orderBy('instructor_id')->get(['instructor_id', 'weight'])
                ->map->only(['instructor_id', 'weight'])->values()->all()
                === collect($attributes['instructors'])->sortBy('instructor_id')->values()->all();

        if (! $same) {
            throw new DomainException('Provider reference already belongs to a different payment.');
        }

        $payment->wasRecentlyCreated = false;

        return $payment;
    }
}
