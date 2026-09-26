<?php

namespace App\Application\Ledger;

use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Payouts\PayoutStatus;
use App\Models\InstructorLedgerEntry;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;

class CalculateInstructorBalance
{
    public function for(int $instructorId, string $currency, CarbonImmutable $asOf): array
    {
        $ledger = InstructorLedgerEntry::query()
            ->where('instructor_id', $instructorId)
            ->where('currency', $currency)
            ->where('earned_at', '<=', $asOf)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN amount_minor ELSE 0 END), 0) AS earned_minor,
                 COALESCE(SUM(CASE WHEN type <> ? THEN amount_minor ELSE 0 END), 0) AS adjusted_minor',
                [LedgerEntryType::Earning->value, LedgerEntryType::Earning->value],
            )->first();

        $earned = (int) $ledger->earned_minor;
        $adjusted = (int) $ledger->adjusted_minor;
        $net = $earned + $adjusted;
        $items = PayoutItem::query()
            ->join('payouts', 'payouts.id', '=', 'payout_items.payout_id')
            ->where('payouts.instructor_id', $instructorId)
            ->where('payouts.currency', $currency);

        $paid = (clone $items)
            ->where('payouts.status', PayoutStatus::Succeeded->value)
            ->where('payouts.completed_at', '<=', $asOf)
            ->sum('payout_items.amount_minor');
        $reserved = (clone $items)
            ->whereIn('payouts.status', [
                PayoutStatus::Pending->value,
                PayoutStatus::Processing->value,
                PayoutStatus::Unknown->value,
            ])
            ->where('payouts.created_at', '<=', $asOf)
            ->whereNull('payout_items.released_at')
            ->sum('payout_items.amount_minor');

        return [
            'earned_minor' => $earned,
            'adjusted_minor' => $adjusted,
            'net_earned_minor' => $net,
            'paid_minor' => (int) $paid,
            'reserved_minor' => (int) $reserved,
            'outstanding_minor' => max(0, $net - $paid - $reserved),
            'deficit_minor' => max(0, $paid - $net),
        ];
    }
}
