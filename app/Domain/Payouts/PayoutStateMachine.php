<?php

namespace App\Domain\Payouts;

final class PayoutStateMachine
{
    public function transition(PayoutStatus $from, PayoutStatus $to): PayoutStatus
    {
        $allowed = match ($from) {
            PayoutStatus::Pending => [PayoutStatus::Processing],
            PayoutStatus::Processing => [PayoutStatus::Succeeded, PayoutStatus::PermanentlyFailed, PayoutStatus::Unknown],
            PayoutStatus::Unknown => [PayoutStatus::Succeeded, PayoutStatus::PermanentlyFailed, PayoutStatus::Unknown],
            PayoutStatus::Succeeded => [PayoutStatus::Succeeded],
            PayoutStatus::PermanentlyFailed => [PayoutStatus::PermanentlyFailed],
            PayoutStatus::Cancelled => [PayoutStatus::Cancelled],
        };

        if (! in_array($to, $allowed, true)) {
            throw new InvalidPayoutTransition("Cannot transition payout from {$from->value} to {$to->value}.");
        }

        return $to;
    }

    public function isTerminal(PayoutStatus $status): bool
    {
        return in_array($status, [
            PayoutStatus::Succeeded,
            PayoutStatus::PermanentlyFailed,
            PayoutStatus::Cancelled,
        ], true);
    }
}
