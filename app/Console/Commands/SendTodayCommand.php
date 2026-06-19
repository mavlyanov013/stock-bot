<?php

namespace App\Console\Commands;

use App\Services\StockMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendTodayCommand extends Command
{
    protected $signature = 'stocks:send-today {--date= : Filter by date (Y-m-d), defaults to today}';

    protected $description = 'Send today\'s stock prices summary to Telegram';

    public function handle(StockMonitorService $monitor): int
    {
        $date = $this->option('date');

        if ($date !== null && $date !== '') {
            try {
                Carbon::parse($date);
            } catch (\Throwable) {
                $this->error("Invalid date: {$date}. Use format Y-m-d (e.g. 2026-06-19).");

                return self::FAILURE;
            }
        }

        $this->info('Building today\'s summary...'.($date ? " (date: {$date})" : ''));

        $sent = $monitor->sendTodaySummary($this, $date ?: null);

        if ($sent) {
            $this->info('Today\'s summary sent.');
        } else {
            $this->warn('No summary sent — no stocks matched the date filter or Telegram was skipped.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
