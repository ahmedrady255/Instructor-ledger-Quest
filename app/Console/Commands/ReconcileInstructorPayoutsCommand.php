<?php

namespace App\Console\Commands;

use App\Domain\Payouts\PayoutStatus;
use App\Jobs\CheckPayoutStatus;
use App\Models\Payout;
use Illuminate\Console\Command;

class ReconcileInstructorPayoutsCommand extends Command
{
    protected $signature = 'instructors:reconcile-payouts {--older-than=5m} {--limit=1000}';

    protected $description = 'Queue status checks for stale uncertain payouts';

    public function handle(): int
    {
        $seconds = $this->parseAge((string) $this->option('older-than'));
        if ($seconds === null) {
            $this->error('The --older-than value must use s, m, h, or d, for example 5m.');

            return self::INVALID;
        }

        $payouts = Payout::query()
            ->whereIn('status', [PayoutStatus::Processing->value, PayoutStatus::Unknown->value])
            ->where('created_at', '<=', now()->subSeconds($seconds))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $payouts->each(fn (string $id) => CheckPayoutStatus::dispatch($id)->onQueue('reconciliation'));
        $this->info("Queued {$payouts->count()} payout reconciliation check(s).");

        return self::SUCCESS;
    }

    private function parseAge(string $age): ?int
    {
        if (! preg_match('/^(\d+)([smhd])$/', $age, $matches)) {
            return null;
        }

        return (int) $matches[1] * match ($matches[2]) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        };
    }
}
