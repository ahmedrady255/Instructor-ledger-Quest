<?php

namespace App\Application\Payouts;

use App\Application\Ledger\CalculateInstructorBalance;
use App\Application\Ledger\RefreshInstructorBalanceSnapshot;
use App\Domain\Ledger\LedgerEntryType;
use App\Domain\Payouts\PayoutStatus;
use App\Jobs\ProcessInstructorPayout;
use App\Models\InstructorLedgerEntry;
use App\Models\Payout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateInstructorPayout
{
    public function __construct(
        private CalculateInstructorBalance $calculateBalance,
        private RefreshInstructorBalanceSnapshot $refreshBalance,
    ) {}

    public function handle(int $instructorId, string $currency): ?Payout
    {
        try {
            return DB::transaction(function () use ($instructorId, $currency) {
                $balance = $this->calculateBalance->for($instructorId, $currency, now('UTC')->toImmutable());
                $available = $balance['outstanding_minor'];
                $threshold = (int) config("ledger.payout_thresholds.{$currency}", 0);

                if ($available <= 0 || $available < $threshold) {
                    return null;
                }

                $entries = InstructorLedgerEntry::query()
                    ->leftJoin('payout_items', function ($join) {
                        $join->on('payout_items.ledger_entry_id', '=', 'instructor_ledger_entries.id')
                            ->whereNull('payout_items.released_at');
                    })
                    ->whereNull('payout_items.id')
                    ->where('instructor_ledger_entries.instructor_id', $instructorId)
                    ->where('instructor_ledger_entries.currency', $currency)
                    ->where('instructor_ledger_entries.type', LedgerEntryType::Earning->value)
                    ->where('instructor_ledger_entries.amount_minor', '>', 0)
                    ->where('instructor_ledger_entries.earned_at', '<=', now('UTC'))
                    ->orderBy('instructor_ledger_entries.earned_at')
                    ->orderBy('instructor_ledger_entries.id')
                    ->limit((int) config('ledger.max_entries_per_payout', 1000))
                    ->lock('for update skip locked')
                    ->get('instructor_ledger_entries.*');

                $selected = [];
                $remaining = $available;
                foreach ($entries as $entry) {
                    if ($entry->amount_minor <= $remaining) {
                        $selected[] = $entry;
                        $remaining -= $entry->amount_minor;
                    }
                }

                if ($selected === []) {
                    return null;
                }

                $amount = array_sum(array_map(fn ($entry) => $entry->amount_minor, $selected));
                $payout = Payout::query()->create([
                    'instructor_id' => $instructorId,
                    'idempotency_key' => (string) Str::uuid(),
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'destination_snapshot' => config('ledger.payout_destination'),
                    'status' => PayoutStatus::Pending,
                ]);

                foreach ($selected as $entry) {
                    $payout->items()->create([
                        'ledger_entry_id' => $entry->id,
                        'amount_minor' => $entry->amount_minor,
                    ]);
                }

                DB::afterCommit(function () use ($payout, $instructorId, $currency) {
                    ProcessInstructorPayout::dispatch($payout->id)->onQueue('payouts');
                    $this->refreshBalance->handle($instructorId, $currency);
                });

                return $payout;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return null;
            }

            throw $exception;
        }
    }
}
