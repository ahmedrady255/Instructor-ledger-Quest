<?php

namespace App\Console\Commands;

use App\Jobs\RecognizeRevenue;
use App\Models\RevenueScheduleItem;
use Illuminate\Console\Command;

class RecognizeRevenueCommand extends Command
{
    protected $signature = 'revenue:recognize {--limit=1000}';

    protected $description = 'Queue due revenue schedules for recognition';

    public function handle(): int
    {
        $paymentIds = RevenueScheduleItem::query()
            ->where('status', 'PENDING')
            ->whereDate('service_date', '<=', now('UTC')->toDateString())
            ->select('payment_id')
            ->distinct()
            ->orderBy('payment_id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('payment_id');

        $paymentIds->each(fn (int $paymentId) => RecognizeRevenue::dispatch($paymentId)->onQueue('recognition'));
        $this->info("Queued {$paymentIds->count()} payment(s) for recognition.");

        return self::SUCCESS;
    }
}
