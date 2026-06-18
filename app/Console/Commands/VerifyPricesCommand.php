<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\UzseService;
use Illuminate\Console\Command;

class VerifyPricesCommand extends Command
{
    protected $signature = 'stocks:verify-prices {symbols?* : Symbols to check (default: UZINP UZTL UZTLP)}';

    protected $description = 'Verify UZSE price parsing against live data';

    public function handle(UzseService $uzse): int
    {
        $symbols = $this->argument('symbols') ?: ['UZINP', 'UZTL', 'UZTLP'];

        $this->info('Symbol | Parsed price | Trade date | Quantity');
        $this->line(str_repeat('-', 55));

        foreach ($symbols as $symbol) {
            $stock = Stock::where('symbol', $symbol)->first();

            if (! $stock?->isin) {
                $this->warn("{$symbol}: not found in DB (run stocks:sync)");

                continue;
            }

            $quote = $uzse->getStockHistory($stock->isin);

            if ($quote === []) {
                $this->error("{$symbol}: no data from UZSE");

                continue;
            }

            $formatted = number_format($quote['price'], 0, '.', ' ')." so'm";
            $this->line(sprintf(
                '%-6s | %-12s | %-10s | %s',
                $symbol,
                $formatted,
                $quote['date'],
                number_format($quote['quantity'], 0, '.', '.'),
            ));
        }

        $this->newLine();
        $this->info('Compare parsed prices with https://www.uzse.uz');

        return self::SUCCESS;
    }
}
