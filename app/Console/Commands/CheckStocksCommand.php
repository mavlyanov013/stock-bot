<?php

namespace App\Console\Commands;

use App\Services\StockMonitorService;
use Illuminate\Console\Command;

class CheckStocksCommand extends Command
{
    protected $signature = 'stocks:check {--loop : Run continuously with 30 second intervals}';

    protected $description = 'Check UZSE stock prices and send Telegram alerts';

    public function handle(StockMonitorService $monitor): int
    {
        if ($this->option('loop')) {
            $this->info('Starting continuous monitoring (30s interval). Press Ctrl+C to stop.');

            while (true) {
                $this->runCheck($monitor);
                sleep(30);
            }
        }

        $this->runCheck($monitor);

        return self::SUCCESS;
    }

    private function runCheck(StockMonitorService $monitor): void
    {
        $this->info('Checking UZSE stock prices...');

        $monitor->check();

        $this->info('Done.');
    }
}
