<?php

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Payouts\PayoutStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstructorLedgerControlReportCommand extends Command
{
    protected $signature = 'instructors:ledger-control {--date=}';

    protected $description = 'Report financial control totals by currency';

    public function handle(): int
    {
        try {
            $end = CarbonImmutable::parse($this->option('date') ?: 'today', 'UTC')->endOfDay();
        } catch (\Throwable) {
            $this->error('The --date value must be a valid date.');

            return self::INVALID;
        }

        $ledger = DB::table('instructor_ledger_entries')
            ->where('earned_at', '<=', $end)
            ->groupBy('instructor_id', 'currency')
            ->select('instructor_id', 'currency')
            ->selectRaw('SUM(CASE WHEN type = ? THEN amount_minor ELSE 0 END) AS recognized_minor', [LedgerEntryType::Earning->value])
            ->selectRaw('SUM(CASE WHEN type <> ? THEN amount_minor ELSE 0 END) AS adjusted_minor', [LedgerEntryType::Earning->value]);
        $payouts = DB::table('payout_items')
            ->join('payouts', 'payouts.id', '=', 'payout_items.payout_id')
            ->where('payouts.created_at', '<=', $end)
            ->groupBy('payouts.instructor_id', 'payouts.currency')
            ->select('payouts.instructor_id', 'payouts.currency')
            ->selectRaw('SUM(CASE WHEN payouts.status = ? AND payouts.completed_at <= ? THEN payout_items.amount_minor ELSE 0 END) AS paid_minor', [PayoutStatus::Succeeded->value, $end])
            ->selectRaw('SUM(CASE WHEN payouts.status IN (?, ?, ?) AND payout_items.released_at IS NULL THEN payout_items.amount_minor ELSE 0 END) AS reserved_minor', [
                PayoutStatus::Pending->value,
                PayoutStatus::Processing->value,
                PayoutStatus::Unknown->value,
            ]);

        $rows = DB::query()->fromSub($ledger, 'ledger')
            ->leftJoinSub($payouts, 'payout', function ($join) {
                $join->on('payout.instructor_id', '=', 'ledger.instructor_id')
                    ->on('payout.currency', '=', 'ledger.currency');
            })
            ->groupBy('ledger.currency')
            ->orderBy('ledger.currency')
            ->select('ledger.currency')
            ->selectRaw('SUM(ledger.recognized_minor) AS recognized_minor')
            ->selectRaw('SUM(ledger.adjusted_minor) AS adjusted_minor')
            ->selectRaw('SUM(COALESCE(payout.paid_minor, 0)) AS paid_minor')
            ->selectRaw('SUM(COALESCE(payout.reserved_minor, 0)) AS reserved_minor')
            ->selectRaw('SUM(GREATEST(0, ledger.recognized_minor + ledger.adjusted_minor - COALESCE(payout.paid_minor, 0) - COALESCE(payout.reserved_minor, 0))) AS outstanding_minor')
            ->get();

        $this->table(
            ['Currency', 'Recognized', 'Adjusted', 'Paid', 'Reserved', 'Outstanding'],
            $rows->map(fn ($row) => [
                $row->currency,
                (string) $row->recognized_minor,
                (string) $row->adjusted_minor,
                (string) $row->paid_minor,
                (string) $row->reserved_minor,
                (string) $row->outstanding_minor,
            ]),
        );

        return self::SUCCESS;
    }
}
