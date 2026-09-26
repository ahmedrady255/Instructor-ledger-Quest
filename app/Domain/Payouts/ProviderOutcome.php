<?php

namespace App\Domain\Payouts;

enum ProviderOutcome: string
{
    case Succeeded = 'SUCCEEDED';
    case PermanentlyFailed = 'PERMANENTLY_FAILED';
    case Pending = 'PENDING';
    case Unknown = 'UNKNOWN';
    case NotFound = 'NOT_FOUND';
}
