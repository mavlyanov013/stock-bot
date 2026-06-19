<?php

namespace App\Console\Commands;

use App\Services\StockMonitorService;
use Illuminate\Console\Command;

class WeeklySummaryCommand extends Command
{
    protected $signature = 'stocks:weekly-summary';

    protected $description = 'Send weekly stock performance summary to Telegram';

    public function handle(StockMonitorService $monitor): int
    {
        $this->info('Building weekly summary...');

        $monitor->sendWeeklySummary($this);

        $this->info('Weekly summary sent.');

        return self::SUCCESS;
    }
}
