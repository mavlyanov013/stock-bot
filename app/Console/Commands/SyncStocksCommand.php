<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\UzseService;
use Illuminate\Console\Command;

class SyncStocksCommand extends Command
{
    protected $signature = 'stocks:sync';

    protected $description = 'Sync stock symbols, ISIN and company names from UZSE';

    public function handle(UzseService $uzse): int
    {
        $securities = $uzse->getSecurities();

        if ($securities === []) {
            $this->error('No securities fetched from UZSE.');

            return self::FAILURE;
        }

        $count = 0;

        foreach ($securities as $security) {
            Stock::updateOrCreate(
                ['symbol' => $security['symbol']],
                [
                    'isin' => $security['isin'],
                    'company_name' => $security['company_name'],
                ],
            );
            $count++;
        }

        $this->info("Synced {$count} stocks.");

        return self::SUCCESS;
    }
}
