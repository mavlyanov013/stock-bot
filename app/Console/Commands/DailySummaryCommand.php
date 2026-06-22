<?php

namespace App\Console\Commands;

use App\Services\StockMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DailySummaryCommand extends Command
{
    protected $signature = 'stocks:daily-summary {--date= : Filter by date (Y-m-d), defaults to today}';

    protected $description = 'Send daily stock market close summary to Telegram';

    public function handle(StockMonitorService $monitor): int
    {
        $date = $this->option('date');

        if ($date !== null && $date !== '') {
            try {
                Carbon::parse($date);
            } catch (\Throwable) {
                $this->error("Invalid date: {$date}. Use format Y-m-d (e.g. 2026-06-22).");

                return self::FAILURE;
            }
        }

        $this->info('Building daily summary...'.($date ? " (date: {$date})" : ''));

        $sent = $monitor->sendDailySummary($this, $date ?: null);

        if ($sent) {
            $this->info('Daily summary sent.');
        } else {
            $this->warn('No summary sent — no stocks matched the date filter or Telegram was skipped.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
