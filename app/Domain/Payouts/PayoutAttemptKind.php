<?php

namespace App\Domain\Payouts;

enum PayoutAttemptKind: string
{
    case Submission = 'SUBMISSION';
    case Reconciliation = 'RECONCILIATION';
}
