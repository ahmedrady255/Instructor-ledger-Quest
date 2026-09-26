<?php

namespace App\Infrastructure\Payments;

use App\Domain\Payouts\ProviderOutcome;

final readonly class ProviderResult
{
    public function __construct(
        public ProviderOutcome $outcome,
        public ?string $providerReference = null,
        public ?string $responseCode = null,
    ) {}

    public function sanitizedPayload(): array
    {
        return ['outcome' => $this->outcome->value];
    }
}
