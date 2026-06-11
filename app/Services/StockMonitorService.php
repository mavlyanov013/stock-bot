<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Cache;

class StockMonitorService
{
    private const INDEX_CACHE_KEY = 'uzse:last_index';

    private const TIMEZONE = 'Asia/Tashkent';

    public function __construct(
        private UzseService $uzse,
        private TelegramService $telegram,
    ) {}

    public function check(): void
    {
        $minChange = (float) config('services.uzse.min_change', 1);
        $prices = $this->uzse->getPrices();

        foreach ($prices as $quote) {
            $stock = Stock::where('symbol', $quote['symbol'])->first();

            if (! $stock) {
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

                if ($quote['quantity'] > 0 && $newPrice > 100) {
                    $this->telegram->sendMessage($this->formatPriceAlert(
                        $stock,
                        $newPrice,
                        $lastPrice,
                        $change,
                        $pct,
                        $quote['quantity'],
                    ));
                }
            } elseif ($lastPrice === null) {
                $stock->update([
                    'last_price' => $newPrice,
                    'last_checked_at' => now(),
                ]);
            } else {
                $stock->update(['last_checked_at' => now()]);
            }
        }

        $this->checkIndex();
    }

    public function sendTestAlert(): void
    {
        $stock = new Stock([
            'symbol' => 'UZINP',
            'company_name' => "O'zbekneftegaz AJ",
        ]);

        $this->telegram->sendMessage($this->formatPriceAlert(
            $stock,
            newPrice: 33_600,
            lastPrice: 32_750,
            change: 850,
            pct: 2.59,
            quantity: 7,
        ));
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
    ): string {
        $isUp = $change >= 0;
        $direction = $isUp ? "Ko'tarildi" : 'Tushdi';
        $time = $this->formatTime();

        return implode("\n", [
            "<b>📊 {$stock->symbol}</b>  ·  <i>{$direction}</i>",
            '',
            '🏢 <b>Kompaniya</b>',
            $stock->company_name,
            '',
            '─────────────────',
            '💰 <b>Joriy narx</b>',
            "<b>{$this->formatMoney($newPrice)}</b>  {$this->formatPct($pct, $isUp)}",
            '',
            '📉 <b>Oldingi narx</b>',
            $this->formatMoney($lastPrice),
            '',
            ($isUp ? '📈' : '📉').' <b>Narx farqi</b>',
            $this->formatSignedMoney($change),
            '─────────────────',
            '',
            '📦 <b>Savdo miqdori:</b>  '.$this->formatQuantity($quantity).' ta',
            "🕐 <b>Vaqt:</b>  {$time}  (Toshkent)",
        ]);
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
            '<b>'.$this->formatDecimal($newIndex).'</b>  '.$this->formatPct($pct, $isUp),
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
        return number_format($value, 0, '.', '.');
    }

    private function formatPrice(float $value): string
    {
        return $this->trimDecimals($value / 1000);
    }

    private function formatDecimal(float $value): string
    {
        return $this->trimDecimals($value);
    }

    private function trimDecimals(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
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

        return "({$emoji} {$sign}".$this->trimDecimals(abs($pct)).'%)';
    }
}
