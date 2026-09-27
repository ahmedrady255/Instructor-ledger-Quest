<?php

namespace App\Jobs;

use App\Application\Ledger\RefreshInstructorBalanceSnapshot;
use App\Domain\Payouts\PayoutAttemptKind;
use App\Domain\Payouts\PayoutStatus;
use App\Domain\Payouts\ProviderOutcome;
use App\Infrastructure\Payments\AmbiguousProviderException;
use App\Infrastructure\Payments\PaymentProvider;
use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessInstructorPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [10, 30, 90];

    public function __construct(public string $payoutId)
    {
        $this->onQueue('payouts');
    }

    public function handle(): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if (! $payout || in_array($payout->status, [PayoutStatus::Succeeded, PayoutStatus::PermanentlyFailed, PayoutStatus::Cancelled], true)) {
            return;
        }

        if ($payout->status !== PayoutStatus::Pending) {
            CheckPayoutStatus::dispatch($payout->id)->onQueue('reconciliation');

            return;
        }

        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->where('status', PayoutStatus::Pending->value)
            ->update(['status' => PayoutStatus::Processing->value, 'submitted_at' => now()]);

        if ($claimed !== 1) {
            CheckPayoutStatus::dispatch($payout->id)->onQueue('reconciliation');

            return;
        }

        $payout->refresh();
        $attempt = $payout->attempts()->create([
            'kind' => PayoutAttemptKind::Submission,
            'attempt_no' => $payout->attempts()->where('kind', PayoutAttemptKind::Submission->value)->max('attempt_no') + 1,
            'status' => ProviderOutcome::Pending,
            'request_id' => null,
            'started_at' => now(),
        ]);

        try {
            $result = app(PaymentProvider::class)->submitPayout(
                $payout->idempotency_key,
                $payout->amount_minor,
                $payout->currency,
                $payout->destination_snapshot,
            );
        } catch (AmbiguousProviderException) {
            DB::transaction(function () use ($payout, $attempt) {
                Payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Processing->value)
                    ->update(['status' => PayoutStatus::Unknown->value]);
                $attempt->update([
                    'status' => ProviderOutcome::Unknown,
                    'response_code' => 'ambiguous',
                    'response_payload' => ['outcome' => ProviderOutcome::Unknown->value],
                    'finished_at' => now(),
                ]);
            });
            Log::channel('financial')->warning('payout_submission_ambiguous', [
                'payout_id' => $payout->id,
                'status' => PayoutStatus::Unknown->value,
                'idempotency_hash' => hash('sha256', $payout->idempotency_key),
            ]);
            CheckPayoutStatus::dispatch($payout->id)->onQueue('reconciliation');

            return;
        }

        DB::transaction(function () use ($payout, $attempt, $result) {
            $updates = match ($result->outcome) {
                ProviderOutcome::Succeeded => [
                    'status' => PayoutStatus::Succeeded->value,
                    'provider_reference' => $result->providerReference,
                    'completed_at' => now(),
                ],
                ProviderOutcome::PermanentlyFailed => [
                    'status' => PayoutStatus::PermanentlyFailed->value,
                    'failed_at' => now(),
                ],
                default => ['status' => PayoutStatus::Unknown->value],
            };
            $changed = Payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Processing->value)->update($updates);
            $attempt->update([
                'status' => $result->outcome,
                'response_code' => $result->responseCode,
                'response_payload' => $result->sanitizedPayload(),
                'finished_at' => now(),
            ]);

            if ($changed === 1 && $result->outcome === ProviderOutcome::PermanentlyFailed) {
                $payout->items()->whereNull('released_at')->update(['released_at' => now()]);
            }
        });
        Log::channel('financial')->info('payout_submission_completed', [
            'payout_id' => $payout->id,
            'status' => $result->outcome->value,
            'idempotency_hash' => hash('sha256', $payout->idempotency_key),
        ]);

        if ($result->outcome === ProviderOutcome::PermanentlyFailed || $result->outcome === ProviderOutcome::Succeeded) {
            app(RefreshInstructorBalanceSnapshot::class)->handle($payout->instructor_id, $payout->currency);
        } else {
            CheckPayoutStatus::dispatch($payout->id)->onQueue('reconciliation');
        }
    }
}
