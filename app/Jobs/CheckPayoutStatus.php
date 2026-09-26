<?php

namespace App\Jobs;

use App\Application\Ledger\RefreshInstructorBalanceSnapshot;
use App\Domain\Payouts\PayoutAttemptKind;
use App\Domain\Payouts\PayoutStatus;
use App\Domain\Payouts\ProviderOutcome;
use App\Infrastructure\Payments\PaymentProvider;
use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CheckPayoutStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 20;

    public array $backoff = [30, 60, 120, 300, 600, 1200];

    public function __construct(public string $payoutId)
    {
        $this->onQueue('reconciliation');
    }

    public function handle(): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if (! $payout || ! in_array($payout->status, [PayoutStatus::Processing, PayoutStatus::Unknown], true)) {
            return;
        }

        $result = app(PaymentProvider::class)->getPayoutStatus($payout->idempotency_key);
        $terminal = DB::transaction(function () use ($payout, $result) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($payout->status, [PayoutStatus::Processing, PayoutStatus::Unknown], true)) {
                return false;
            }

            $attemptNo = $payout->attempts()->where('kind', PayoutAttemptKind::Reconciliation->value)->max('attempt_no') + 1;
            $payout->attempts()->create([
                'kind' => PayoutAttemptKind::Reconciliation,
                'attempt_no' => $attemptNo,
                'status' => $result->outcome,
                'request_id' => null,
                'response_code' => $result->responseCode,
                'response_payload' => $result->sanitizedPayload(),
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $payout->reconciliation_count++;
            if ($payout->created_at->lte(now()->subMinutes((int) config('ledger.manual_review_after_minutes', 60)))) {
                $payout->manual_review_at ??= now();
            }

            if ($result->outcome === ProviderOutcome::Succeeded) {
                $payout->status = PayoutStatus::Succeeded;
                $payout->provider_reference = $result->providerReference;
                $payout->completed_at = now();
            } elseif ($result->outcome === ProviderOutcome::PermanentlyFailed) {
                $payout->status = PayoutStatus::PermanentlyFailed;
                $payout->failed_at = now();
                $payout->items()->whereNull('released_at')->update(['released_at' => now()]);
            } else {
                $payout->status = PayoutStatus::Unknown;
            }
            $payout->save();

            return in_array($payout->status, [PayoutStatus::Succeeded, PayoutStatus::PermanentlyFailed], true);
        });

        if ($terminal) {
            app(RefreshInstructorBalanceSnapshot::class)->handle($payout->instructor_id, $payout->currency);
        } elseif ($this->job) {
            $this->release($this->backoff[min($payout->reconciliation_count, count($this->backoff) - 1)]);
        }
    }
}
