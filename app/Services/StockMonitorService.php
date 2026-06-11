<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Cache;

class StockMonitorService
{
    private const INDEX_CACHE_KEY = 'uzse:last_index';

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

                $this->telegram->sendMessage($this->formatPriceAlert(
                    $stock,
                    $newPrice,
                    $lastPrice,
                    $change,
                    $pct,
                    $quote['quantity'],
                ));
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

    private function checkIndex(): void
    {
        $newIndex = $this->uzse->getIndex();

        if ($newIndex <= 0) {
            return;
        }

        $previousIndex = Cache::get(self::INDEX_CACHE_KEY);

        if ($previousIndex !== null && abs($newIndex - $previousIndex) > 0.5) {
            $change = $newIndex - $previousIndex;
            $sign = $change >= 0 ? '+' : '';

            $this->telegram->sendMessage(
                "📊 <b>UZSE Market Index</b>\n".
                "Index: <b>".number_format($newIndex, 2)."</b>\n".
                "Change: <b>{$sign}".number_format($change, 2)."</b>"
            );
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
        $sign = $change >= 0 ? '+' : '';
        $changeEmoji = $change >= 0 ? '📈' : '📉';
        $time = now()->format('d.m.Y H:i');

        return "📊 <b>{$stock->symbol}</b> — O'zgarish!\n".
            "🏢 Kompaniya: {$stock->company_name}\n".
            '💰 Narx: <b>'.number_format($newPrice, 2)." so'm</b>\n".
            "📉 Oldingi narx: ".number_format($lastPrice, 2)." so'm\n".
            "{$changeEmoji} O'zgarish: {$sign}".number_format($change, 2)." so'm ({$sign}".number_format($pct, 2)."%)\n".
            '📦 Miqdor: '.number_format($quantity)." ta\n".
            "🕐 Vaqt: {$time}";
    }
}
