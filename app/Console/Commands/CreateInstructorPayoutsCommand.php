<?php

namespace App\Console\Commands;

use App\Application\Payouts\CreateInstructorPayout;
use App\Domain\Ledger\LedgerEntryType;
use App\Models\InstructorLedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CreateInstructorPayoutsCommand extends Command
{
    protected $signature = 'instructors:payout {--currency=EGP} {--limit=1000} {--dry-run}';

    protected $description = 'Reserve payable instructor ledger entries and queue payouts';

    public function handle(CreateInstructorPayout $createPayout): int
    {
        $currency = strtoupper((string) $this->option('currency'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->error('Currency must be a three-letter uppercase code.');

            return self::INVALID;
        }

        $lock = $this->acquireLock("payout-discovery:{$currency}");
        if ($lock === false) {
            $this->info("Payout discovery for {$currency} is already running.");

            return self::SUCCESS;
        }

        try {
            $candidates = InstructorLedgerEntry::query()
                ->leftJoin('payout_items', function ($join) {
                    $join->on('payout_items.ledger_entry_id', '=', 'instructor_ledger_entries.id')
                        ->whereNull('payout_items.released_at');
                })
                ->whereNull('payout_items.id')
                ->where('instructor_ledger_entries.currency', $currency)
                ->where('instructor_ledger_entries.type', LedgerEntryType::Earning->value)
                ->where('instructor_ledger_entries.amount_minor', '>', 0)
                ->where('instructor_ledger_entries.earned_at', '<=', now('UTC'))
                ->select('instructor_ledger_entries.instructor_id')
                ->distinct()
                ->orderBy('instructor_ledger_entries.instructor_id')
                ->limit(max(1, (int) $this->option('limit')))
                ->pluck('instructor_id');

            if ($this->option('dry-run')) {
                $this->info("{$candidates->count()} candidate(s) found; dry run made no changes.");

                return self::SUCCESS;
            }

            $created = 0;
            foreach ($candidates as $instructorId) {
                $created += $createPayout->handle((int) $instructorId, $currency) ? 1 : 0;
            }
            $this->info("Created {$created} payout(s) from {$candidates->count()} candidate(s).");

            return self::SUCCESS;
        } finally {
            $lock?->release();
        }
    }

    private function acquireLock(string $key): Lock|false|null
    {
        try {
            $lock = Cache::lock($key, 30);

            return $lock->get() ? $lock : false;
        } catch (Throwable) {
            return null;
        }
    }
}
