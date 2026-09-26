<?php

use App\Domain\Payouts\InvalidPayoutTransition;
use App\Domain\Payouts\PayoutStateMachine;
use App\Domain\Payouts\PayoutStatus;

it('allows the complete payout transition table', function (PayoutStatus $from, PayoutStatus $to) {
    expect((new PayoutStateMachine)->transition($from, $to))->toBe($to);
})->with([
    'claim' => [PayoutStatus::Pending, PayoutStatus::Processing],
    'provider success' => [PayoutStatus::Processing, PayoutStatus::Succeeded],
    'provider rejection' => [PayoutStatus::Processing, PayoutStatus::PermanentlyFailed],
    'provider ambiguity' => [PayoutStatus::Processing, PayoutStatus::Unknown],
    'reconciled success' => [PayoutStatus::Unknown, PayoutStatus::Succeeded],
    'reconciled failure' => [PayoutStatus::Unknown, PayoutStatus::PermanentlyFailed],
    'still unknown' => [PayoutStatus::Unknown, PayoutStatus::Unknown],
    'succeeded stale retry' => [PayoutStatus::Succeeded, PayoutStatus::Succeeded],
    'failed stale retry' => [PayoutStatus::PermanentlyFailed, PayoutStatus::PermanentlyFailed],
    'cancelled stale retry' => [PayoutStatus::Cancelled, PayoutStatus::Cancelled],
]);

it('rejects every transition outside the table', function (PayoutStatus $from, PayoutStatus $to) {
    expect(fn () => (new PayoutStateMachine)->transition($from, $to))
        ->toThrow(InvalidPayoutTransition::class);
})->with(function () {
    $valid = [
        'PENDING:PROCESSING',
        'PROCESSING:SUCCEEDED',
        'PROCESSING:PERMANENTLY_FAILED',
        'PROCESSING:UNKNOWN',
        'UNKNOWN:SUCCEEDED',
        'UNKNOWN:PERMANENTLY_FAILED',
        'UNKNOWN:UNKNOWN',
        'SUCCEEDED:SUCCEEDED',
        'PERMANENTLY_FAILED:PERMANENTLY_FAILED',
        'CANCELLED:CANCELLED',
    ];
    $cases = [];

    foreach (PayoutStatus::cases() as $from) {
        foreach (PayoutStatus::cases() as $to) {
            if (! in_array("{$from->value}:{$to->value}", $valid, true)) {
                $cases["{$from->value} to {$to->value}"] = [$from, $to];
            }
        }
    }

    return $cases;
});

it('identifies only terminal payout states', function (PayoutStatus $status, bool $terminal) {
    expect((new PayoutStateMachine)->isTerminal($status))->toBe($terminal);
})->with([
    [PayoutStatus::Pending, false],
    [PayoutStatus::Processing, false],
    [PayoutStatus::Unknown, false],
    [PayoutStatus::Succeeded, true],
    [PayoutStatus::PermanentlyFailed, true],
    [PayoutStatus::Cancelled, true],
]);
