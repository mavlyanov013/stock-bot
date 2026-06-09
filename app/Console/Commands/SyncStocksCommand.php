<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\UzseService;
use Illuminate\Console\Command;

class SyncStocksCommand extends Command
{
    protected $signature = 'stocks:sync';

    protected $description = 'Sync stock symbols and company names from UZSE trade results';

    public function handle(UzseService $uzse): int
    {
        $securities = $uzse->getSecurities();

        if ($securities === []) {
            $this->error('No securities fetched from UZSE.');

            return self::FAILURE;
        }

        $count = 0;

        foreach ($securities as $symbol => $companyName) {
            Stock::updateOrCreate(
                ['symbol' => $symbol],
                ['company_name' => $companyName],
            );
            $count++;
        }

        $this->info("Synced {$count} stocks.");

        return self::SUCCESS;
    }
}
