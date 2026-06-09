<?php

namespace App\Console\Commands;

use App\Services\StockMonitorService;
use Illuminate\Console\Command;

class CheckStocksCommand extends Command
{
    protected $signature = 'stocks:check';

    protected $description = 'Check UZSE stock prices and send Telegram alerts';

    public function handle(StockMonitorService $monitor): int
    {
        $this->info('Checking UZSE stock prices...');

        $monitor->check();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
