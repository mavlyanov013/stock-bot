<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StockMonitorService
{
    private const INDEX_CACHE_KEY = 'uzse:last_index';

    private const TIMEZONE = 'Asia/Tashkent';

    public function __construct(
        private UzseService $uzse,
        private TelegramService $telegram,
    ) {}

    public function check(?Command $command = null): void
    {
        $minChange = (float) config('services.uzse.min_change', 1);
        $stocks = Stock::whereNotNull('isin')->get();

        if ($stocks->isEmpty()) {
            Log::warning('No stocks with ISIN found. Run stocks:sync first.');
            $command?->warn('No stocks with ISIN found. Run stocks:sync first.');

            return;
        }

        $command?->info("Loaded {$stocks->count()} stocks to check.");

        foreach ($stocks as $stock) {
            $command?->info("Processing: {$stock->symbol}");
            logger()->info("Calling UzseService for: {$stock->symbol}", ['isin' => $stock->isin]);

            try {
                $quote = $this->uzse->getStockHistory($stock->isin);
            } catch (\Throwable $e) {
                Log::error('Stock check failed', [
                    'symbol' => $stock->symbol,
                    'isin' => $stock->isin,
                    'error' => $e->getMessage(),
                ]);
                $command?->error("Failed {$stock->symbol}: {$e->getMessage()}");

                sleep(2);

                continue;
            }

            logger()->info("Received response for: {$stock->symbol}", [
                'has_data' => $quote !== [],
            ]);

            if ($quote === []) {
                Log::warning('No history fetched for stock', ['symbol' => $stock->symbol, 'isin' => $stock->isin]);
                $command?->warn("No history for {$stock->symbol}, skipping.");
                sleep(2);

                continue;
            }

            $newPrice = $quote['price'];
            $lastPrice = $stock->last_price;

            if ($lastPrice !== null && abs($newPrice - $lastPrice) >= $minChange) {
                $change = $newPrice - $lastPrice;
                $pct = $lastPrice != 0 ? ($change / $lastPrice) * 100 : 0;

                $stock->update([
                    'prev_price' => $lastPrice,
                    'last_price' => $newPrice,
                    'last_checked_at' => now(),
                ]);

                if ($this->isValidTrade($quote)) {
                    try {
                        $this->telegram->sendMessage($this->formatPriceAlert(
                            $stock,
                            $newPrice,
                            $lastPrice,
                            $change,
                            $pct,
                            $quote['quantity'],
                            $quote['date'] ?? null,
                        ));
                    } catch (\Throwable $e) {
                        Log::error('Telegram alert failed', [
                            'symbol' => $stock->symbol,
                            'error' => $e->getMessage(),
                        ]);
                        $command?->error("Telegram alert failed for {$stock->symbol}: {$e->getMessage()}");
                    }
                }
            } elseif ($lastPrice === null) {
                $stock->update([
                    'last_price' => $newPrice,
                    'last_checked_at' => now(),
                ]);
            } elseif ($this->isBaselineCorrection($lastPrice, $newPrice)) {
                Log::info('Correcting baseline price after parse fix', [
                    'symbol' => $stock->symbol,
                    'old' => $lastPrice,
                    'new' => $newPrice,
                ]);
                $stock->update([
                    'last_price' => $newPrice,
                    'last_checked_at' => now(),
                ]);
            } else {
                $stock->update(['last_checked_at' => now()]);
            }

            sleep(2);
        }

        $this->checkIndex();
    }

    public function sendTestAlert(): void
    {
        $stock = new Stock([
            'symbol' => 'UZINP',
            'company_name' => "O'zbekinvest EISK AJ",
        ]);

        $this->telegram->sendMessage($this->formatPriceAlert(
            $stock,
            newPrice: 2000,
            lastPrice: 1950,
            change: 50,
            pct: 2.56,
            quantity: 7,
        ));
    }

    private function isValidTrade(array $quote): bool
    {
        return $quote['quantity'] > 0 && $quote['price'] > 100;
    }

    /**
     * Detect prices stored with the old broken parser (comma truncated + *1000).
     * Example: UZTL stored 6000 but real price is 6398.
     */
    private function isBaselineCorrection(float $stored, float $actual): bool
    {
        if ($stored <= 0 || $actual <= 0) {
            return false;
        }

        $ratio = $actual / $stored;

        return $ratio > 1.03 && $ratio < 1.15;
    }

    private function checkIndex(): void
    {
        $newIndex = $this->uzse->getIndex();

        if ($newIndex <= 0) {
            return;
        }

        $previousIndex = Cache::get(self::INDEX_CACHE_KEY);

        if ($previousIndex !== null && abs($newIndex - $previousIndex) > 0.5) {
            $change = $newIndex - $previousIndex;
            $isUp = $change >= 0;
            $pct = $previousIndex != 0 ? ($change / $previousIndex) * 100 : 0;

            $this->telegram->sendMessage($this->formatIndexAlert($newIndex, $previousIndex, $change, $pct, $isUp));
        }

        Cache::forever(self::INDEX_CACHE_KEY, $newIndex);
    }

    private function formatPriceAlert(
        Stock $stock,
        float $newPrice,
        float $lastPrice,
        float $change,
        float $pct,
        int $quantity,
        ?string $tradeDate = null,
    ): string {
        $isUp = $change >= 0;
        $direction = $isUp ? "Ko'tarildi" : 'Tushdi';
        $time = $this->formatTime();
        $tradeDay = $tradeDate ? trim(explode(',', $tradeDate)[0]) : null;

        return implode("\n", [
            "<b>📊 {$stock->symbol}</b>  ·  <i>{$direction}</i>",
            '',
            '🏢 <b>Kompaniya</b>',
            $this->displayCompanyName($stock),
            '',
            '─────────────────',
            '💰 <b>Joriy narx</b>',
            $this->formatMoney($newPrice).'  '.$this->formatPct($pct, $isUp),
            '',
            '📉 <b>Oldingi narx</b>',
            $this->formatMoney($lastPrice),
            '',
            ($isUp ? '📈' : '📉').' <b>Narx farqi</b>',
            $this->formatSignedMoney($change),
            '─────────────────',
            '',
            '📦 <b>Savdo miqdori:</b>  '.$this->formatQuantity($quantity).' ta',
            '📅 <b>Savdo kuni:</b>  '.($tradeDay ?? '—'),
            "🕐 <b>Xabar vaqti:</b>  {$time}  (Toshkent)",
        ]);
    }

    private function displayCompanyName(Stock $stock): string
    {
        $shortNames = [
            'UZINP' => "O'zbekinvest EISK AJ",
            'UZTL' => "O'zbektelekom AJ",
            'UZTLP' => "O'zbektelekom AJ (privilegiyali)",
        ];

        return $shortNames[$stock->symbol] ?? $stock->company_name;
    }

    private function formatIndexAlert(
        float $newIndex,
        float $previousIndex,
        float $change,
        float $pct,
        bool $isUp,
    ): string {
        $direction = $isUp ? "Ko'tarildi" : 'Tushdi';
        $time = $this->formatTime();

        return implode("\n", [
            '<b>📊 UZSE bozor indeksi</b>  ·  <i>'.$direction.'</i>',
            '',
            '─────────────────',
            '📈 <b>Joriy indeks</b>',
            $this->formatDecimal($newIndex).'  '.$this->formatPct($pct, $isUp),
            '',
            '📉 <b>Oldingi indeks</b>',
            $this->formatDecimal($previousIndex),
            '',
            ($isUp ? '📈' : '📉').' <b>Indeks farqi</b>',
            $this->formatSignedDecimal($change),
            '─────────────────',
            '',
            "🕐 <b>Vaqt:</b>  {$time}  (Toshkent)",
        ]);
    }

    private function formatTime(): string
    {
        return now()->setTimezone(self::TIMEZONE)->format('d.m.Y, H:i');
    }

    private function formatMoney(float $value): string
    {
        return $this->formatPrice($value)." so'm";
    }

    private function formatSignedMoney(float $value): string
    {
        $sign = $value >= 0 ? '+' : '−';

        return $sign.$this->formatPrice(abs($value))." so'm";
    }

    private function formatQuantity(int $value): string
    {
        return $this->formatPrice($value);
    }

    private function formatPrice(float $value): string
    {
        if ($this->isWholeNumber($value)) {
            return number_format((int) round($value), 0, '.', ' ');
        }

        $formatted = number_format($value, 2, '.', ' ');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private function formatDecimal(float $value): string
    {
        return $this->formatPrice($value);
    }

    private function isWholeNumber(float $value): bool
    {
        return abs($value - round($value)) < 0.00001;
    }

    private function formatWithDecimals(float $value): string
    {
        return $this->formatPrice($value);
    }

    private function formatSignedDecimal(float $value): string
    {
        $sign = $value >= 0 ? '+' : '−';

        return $sign.$this->formatDecimal(abs($value));
    }

    private function formatPct(float $pct, bool $isUp): string
    {
        $emoji = $isUp ? '🟢' : '🔴';
        $sign = $pct >= 0 ? '+' : '−';

        return "({$emoji} {$sign}".number_format(abs($pct), 2).'%)';
    }
}
