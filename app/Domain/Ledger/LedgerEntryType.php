<?php

namespace App\Domain\Ledger;

enum LedgerEntryType: string
{
    case Earning = 'EARNING';
    case RefundAdjustment = 'REFUND_ADJUSTMENT';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
}
