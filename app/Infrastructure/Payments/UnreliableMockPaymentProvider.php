<?php

namespace App\Infrastructure\Payments;

use App\Domain\Payouts\ProviderOutcome;
use App\Models\MockProviderTransfer;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UnreliableMockPaymentProvider implements PaymentProvider
{
    public function submitPayout(string $key, int $amountMinor, string $currency, array $destination): ProviderResult
    {
        [$result, $ambiguous] = DB::transaction(function () use ($key, $amountMinor, $currency, $destination) {
            $scenario = (string) config('ledger.mock_provider_outcome', 'success');
            $outcome = match ($scenario) {
                'success', 'timeout_after_success' => ProviderOutcome::Succeeded,
                'permanent_failure' => ProviderOutcome::PermanentlyFailed,
                default => throw new DomainException("Unsupported mock provider outcome: {$scenario}"),
            };
            $reference = $outcome === ProviderOutcome::Succeeded ? 'mock_'.Str::lower((string) Str::ulid()) : null;
            $inserted = DB::table('mock_provider_transfers')->insertOrIgnore([
                'idempotency_key' => $key,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'destination_snapshot' => json_encode($destination, JSON_THROW_ON_ERROR),
                'status' => $outcome->value,
                'provider_reference' => $reference,
                'submission_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $transfer = MockProviderTransfer::query()->where('idempotency_key', $key)->lockForUpdate()->firstOrFail();

            if ($transfer->amount_minor !== $amountMinor || $transfer->currency !== $currency || $transfer->destination_snapshot !== $destination) {
                throw new DomainException('Provider idempotency key was reused with different payout data.');
            }

            if (! $inserted) {
                $transfer->increment('submission_count');
            }

            return [
                $this->resultFor($transfer),
                (bool) $inserted && $scenario === 'timeout_after_success',
            ];
        });

        if ($ambiguous) {
            throw new AmbiguousProviderException('Provider completed the transfer but the response timed out.');
        }

        return $result;
    }

    public function getPayoutStatus(string $key): ProviderResult
    {
        $transfer = MockProviderTransfer::query()->where('idempotency_key', $key)->first();

        return $transfer
            ? $this->resultFor($transfer)
            : new ProviderResult(ProviderOutcome::NotFound, responseCode: 'not_found');
    }

    private function resultFor(MockProviderTransfer $transfer): ProviderResult
    {
        return new ProviderResult(
            $transfer->status,
            $transfer->provider_reference,
            $transfer->status === ProviderOutcome::PermanentlyFailed ? 'rejected' : 'ok',
        );
    }
}
