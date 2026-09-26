<?php

namespace App\Domain\Payouts;

enum PayoutStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Unknown = 'UNKNOWN';
    case Succeeded = 'SUCCEEDED';
    case PermanentlyFailed = 'PERMANENTLY_FAILED';
    case Cancelled = 'CANCELLED';
}
