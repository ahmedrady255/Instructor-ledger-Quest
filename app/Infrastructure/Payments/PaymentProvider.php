<?php

namespace App\Infrastructure\Payments;

interface PaymentProvider
{
    public function submitPayout(string $key, int $amountMinor, string $currency, array $destination): ProviderResult;

    public function getPayoutStatus(string $key): ProviderResult;
}
