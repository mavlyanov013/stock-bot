<?php

namespace App\Console\Commands;

use App\Services\StockMonitorService;
use Illuminate\Console\Command;

class TestAlertCommand extends Command
{
    protected $signature = 'stocks:test-alert';

    protected $description = 'Send a fake price alert to Telegram for preview';

    public function handle(StockMonitorService $monitor): int
    {
        $this->info('Sending test alert to Telegram...');

        $monitor->sendTestAlert();

        $this->info('Test alert sent.');

        return self::SUCCESS;
    }
}
