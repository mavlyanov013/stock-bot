<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StockMonitorService
{
    private const INDEX_CACHE_KEY = 'uzse:last_index';

    private const WEEK_START_CACHE_KEY = 'week_start_date';

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

        $this->ensureWeekTracking();

        $processed = 0;
        $savedCount = 0;
        $alertCount = 0;

        foreach ($stocks as $stock) {
            $processed++;
            $command?->info("Processing: {$stock->symbol}");

            try {
                $history = $this->uzse->getStockHistory($stock->isin);
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

            if (empty($history) || $history['price'] <= 0) {
                Log::info('No history', ['symbol' => $stock->symbol]);
                sleep(2);

                continue;
            }

            Log::info('Got history', ['symbol' => $stock->symbol, 'price' => $history['price']]);

            $previousPrice = $stock->last_price;
            $newPrice = $history['price'];

            $this->updateWeekOpenPrice($stock, $newPrice, $history);

            $priceChanged = $previousPrice !== null
                && abs($newPrice - $previousPrice) >= $minChange;

            if ($priceChanged) {
                $stock->prev_price = $previousPrice;
            }

            $stock->last_price = $history['price'];
            $stock->last_checked_at = now();
            $saved = $stock->save();

            Log::info('Save result', [
                'symbol' => $stock->symbol,
                'saved' => $saved,
                'price' => $stock->last_price,
            ]);

            if ($saved) {
                $savedCount++;
            }

            if ($this->shouldSendPriceAlert($history, $priceChanged)) {
                $change = $newPrice - $previousPrice;
                $pct = $previousPrice != 0 ? ($change / $previousPrice) * 100 : 0;

                try {
                    $this->telegram->sendMessage($this->formatPriceAlert(
                        $stock,
                        $newPrice,
                        $previousPrice,
                        $change,
                        $pct,
                        $history['quantity'],
                        $history['date'] ?? null,
                    ));
                    $alertCount++;
                } catch (\Throwable $e) {
                    Log::error('Telegram alert failed', [
                        'symbol' => $stock->symbol,
                        'error' => $e->getMessage(),
                    ]);
                    $command?->error("Telegram alert failed for {$stock->symbol}: {$e->getMessage()}");
                }
            }

            sleep(2);
        }

        Log::info('Check complete', [
            'total' => $processed,
            'saved' => $savedCount,
            'alerted' => $alertCount,
        ]);

        $this->checkIndex();
    }

    public function sendTodaySummary(?Command $command = null, ?string $date = null): bool
    {
        $stocks = Stock::whereNotNull('isin')->get();
        $targetDate = $this->resolveSummaryDate($date);
        $results = [];

        Log::info('Today summary starting', [
            'input_date' => $date,
            'target_date' => $targetDate,
            'stock_count' => $stocks->count(),
        ]);

        if ($stocks->isEmpty()) {
            $command?->warn('No stocks found.');
            Log::warning('Today summary skipped: no stocks');

            return false;
        }

        foreach ($stocks as $stock) {
            try {
                $history = $this->uzse->getStockHistory($stock->isin);
            } catch (\Throwable $e) {
                Log::warning('Today summary: failed to fetch stock', [
                    'symbol' => $stock->symbol,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (empty($history) || $history['price'] <= 0) {
                continue;
            }

            $historyDate = $this->normalizeHistoryDate($history['date'] ?? '');

            if ($historyDate !== $targetDate) {
                continue;
            }

            $results[] = [
                'symbol' => $stock->symbol,
                'price' => $history['price'],
                'change' => $history['change'],
            ];

            sleep(2);
        }

        Log::info('Today summary matched stocks', [
            'count' => count($results),
            'target_date' => $targetDate,
            'symbols' => array_column($results, 'symbol'),
        ]);

        if ($results === []) {
            $command?->warn("No stocks with trades for {$targetDate}.");
            Log::warning('Today summary skipped: no stocks for date', ['date' => $targetDate]);

            return false;
        }

        usort($results, fn (array $a, array $b) => $b['change'] <=> $a['change']);

        $message = $this->formatTodaySummary($results, $targetDate);

        Log::info('Today summary message ready', ['message' => $message]);

        $command?->info('--- Telegram message ---');
        $command?->line($message);
        $command?->info('--- end message ---');

        Log::info('Calling Telegram sendMessage for today summary');

        $this->telegram->sendMessage($message);

        Log::info('Telegram sendMessage completed for today summary');

        return true;
    }

    private function resolveSummaryDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return now()->setTimezone(self::TIMEZONE)->format('d.m.Y');
        }

        return Carbon::parse($date, self::TIMEZONE)->format('d.m.Y');
    }

    private function normalizeHistoryDate(string $date): ?string
    {
        $date = trim(explode(',', $date)[0]);

        if ($date === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $matches)) {
            return sprintf('%02d.%02d.%04d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3])
                ->format('d.m.Y');
        }

        return null;
    }

    private function isHistoryDate(string $date, string $targetDate): bool
    {
        return $this->normalizeHistoryDate($date) === $targetDate;
    }

    private function formatTodaySummary(array $results, string $today): string
    {
        $lines = [
            "<b>📊 Bugungi Natijalar — {$today}</b>",
            '',
        ];

        foreach ($results as $row) {
            $lines[] = $this->formatTodaySummaryLine($row['symbol'], $row['price'], $row['change']);
        }

        return implode("\n", $lines);
    }

    private function formatTodaySummaryLine(string $symbol, float $price, float $change): string
    {
        $isUp = $change >= 0;
        $emoji = $isUp ? '🟢' : '🔴';
        $arrow = $isUp ? '▲' : '▼';

        return sprintf(
            '%s %-5s — %s (%s %s)',
            $emoji,
            $symbol,
            $this->formatMoney($price),
            $arrow,
            $this->formatSignedMoney($change),
        );
    }

    public function sendWeeklySummary(?Command $command = null): void
    {
        $stocks = Stock::whereNotNull('isin')->get();

        if ($stocks->isEmpty()) {
            $command?->warn('No stocks found.');
            Log::warning('Weekly summary skipped: no stocks');

            return;
        }

        $results = [];

        foreach ($stocks as $stock) {
            if ($stock->week_open_price === null || $stock->week_open_price <= 0) {
                continue;
            }

            try {
                $quote = $this->uzse->getStockHistory($stock->isin);
            } catch (\Throwable $e) {
                Log::warning('Weekly summary: failed to fetch stock', [
                    'symbol' => $stock->symbol,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($quote === [] || $quote['quantity'] <= 0) {
                continue;
            }

            $currentPrice = $quote['price'];
            $weekOpen = $stock->week_open_price;
            $pct = (($currentPrice - $weekOpen) / $weekOpen) * 100;

            $results[] = [
                'symbol' => $stock->symbol,
                'pct' => $pct,
                'week_open' => $weekOpen,
                'current' => $currentPrice,
            ];

            sleep(2);
        }

        if ($results === []) {
            $command?->warn('No stocks with weekly data to summarize.');
            Log::warning('Weekly summary skipped: no qualifying stocks');

            return;
        }

        usort($results, fn (array $a, array $b) => $b['pct'] <=> $a['pct']);

        $this->telegram->sendMessage($this->formatWeeklySummary($results));
    }

    private function ensureWeekTracking(): void
    {
        $weekStart = now()
            ->setTimezone(self::TIMEZONE)
            ->startOfWeek(Carbon::MONDAY)
            ->format('Y-m-d');

        if (Cache::get(self::WEEK_START_CACHE_KEY) !== $weekStart) {
            Cache::forever(self::WEEK_START_CACHE_KEY, $weekStart);
            Stock::query()->update(['week_open_price' => null]);
        }
    }

    private function updateWeekOpenPrice(Stock $stock, float $price, array $quote): void
    {
        if ($quote === [] || $price <= 0) {
            return;
        }

        if ($stock->week_open_price === null) {
            Log::info('Attempting save', [
                'symbol' => $stock->symbol,
                'price' => $price,
                'field' => 'week_open_price',
            ]);

            $result = $stock->update(['week_open_price' => $price]);
            $stock->refresh();

            Log::info('Saved', [
                'symbol' => $stock->symbol,
                'price' => $stock->week_open_price,
                'result' => $result,
            ]);
        }
    }

    private function formatWeeklySummary(array $results): string
    {
        $weekStart = Cache::get(self::WEEK_START_CACHE_KEY);
        $tz = self::TIMEZONE;

        $startDate = $weekStart
            ? Carbon::parse($weekStart, $tz)->format('d.m.Y')
            : now()->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->format('d.m.Y');

        $endDate = now()->setTimezone($tz)->format('d.m.Y');

        $gainers = array_filter($results, fn (array $r) => $r['pct'] > 0.005);
        $losers = array_filter($results, fn (array $r) => $r['pct'] < -0.005);
        $unchanged = array_filter($results, fn (array $r) => abs($r['pct']) <= 0.005);

        usort($gainers, fn (array $a, array $b) => $b['pct'] <=> $a['pct']);
        usort($losers, fn (array $a, array $b) => $a['pct'] <=> $b['pct']);

        $lines = [
            '<b>📊 Haftalik Natijalar</b>',
            "📅 {$startDate} — {$endDate}",
            '',
            '🏆 <b>Ko\'tarilganlar:</b>',
        ];

        if ($gainers === []) {
            $lines[] = '—';
        } else {
            foreach ($gainers as $row) {
                $lines[] = $this->formatWeeklySummaryLine($row, '🟢');
            }
        }

        $lines[] = '';
        $lines[] = '📉 <b>Tushganlar:</b>';

        if ($losers === []) {
            $lines[] = '—';
        } else {
            foreach ($losers as $row) {
                $lines[] = $this->formatWeeklySummaryLine($row, '🔴');
            }
        }

        $lines[] = '';
        $unchangedSymbols = array_map(fn (array $r) => $r['symbol'], array_values($unchanged));

        if ($unchangedSymbols === []) {
            $lines[] = '➖ <b>O\'zgarmagan:</b> —';
        } else {
            $lines[] = '➖ <b>O\'zgarmagan:</b> '.implode(', ', $unchangedSymbols);
        }

        return implode("\n", $lines);
    }

    private function formatWeeklySummaryLine(array $row, string $emoji): string
    {
        $pct = $this->formatSignedWeeklyPct($row['pct']);
        $open = $this->formatPrice($row['week_open']);
        $current = $this->formatPrice($row['current']);

        return sprintf(
            '%s %-5s — %s (%s → %s so\'m)',
            $emoji,
            $row['symbol'],
            $pct,
            $open,
            $current,
        );
    }

    private function formatSignedWeeklyPct(float $pct): string
    {
        $sign = $pct >= 0 ? '+' : '−';

        return $sign.number_format(abs($pct), 2).'%';
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

    private function shouldSendPriceAlert(array $quote, bool $priceChanged): bool
    {
        return $priceChanged
            && $quote['quantity'] > 0
            && $quote['price'] > 100;
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
