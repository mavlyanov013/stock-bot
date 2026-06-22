<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class UzseService
{
    private const BASE_URL = 'https://www.uzse.uz';

    public function getSecurities(): array
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->htmlHeaders())
                ->timeout(30)
                ->get(self::BASE_URL.'/isu_infos/names', ['mkt_id' => 'STK']);
        } catch (ConnectionException|RequestException|\Throwable $e) {
            Log::error('Failed to fetch UZSE securities list', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::error('Failed to fetch UZSE securities list', ['status' => $response->status()]);

            return [];
        }

        $securities = [];

        foreach ($response->json() as $row) {
            if (! is_array($row) || count($row) < 3) {
                continue;
            }

            [$isin, $symbol, $companyName] = $row;
            $securities[$symbol] = [
                'isin' => $isin,
                'symbol' => $symbol,
                'company_name' => $this->extractCompanyName($companyName),
            ];
        }

        return $securities;
    }

    public function getStockHistory(string $isin): array
    {
        $crawler = $this->fetchStockHistoryPage($isin);

        if ($crawler === null) {
            return [];
        }

        $latest = $this->parseLatestTrade($crawler);

        if ($latest !== []) {
            return $this->stripHistoryTimestamp($latest);
        }

        $daily = $this->parseDailyHistoryLatestRow($crawler);

        if ($daily === []) {
            return [];
        }

        return $this->stripHistoryTimestamp($daily);
    }

    /**
     * @return list<array{date: string, price: float, change: float, quantity: int, volume: float}>
     */
    public function getStockHistoryDays(string $isin): array
    {
        $crawler = $this->fetchStockHistoryPage($isin);

        if ($crawler === null) {
            return [];
        }

        $rows = $this->parseAllStockHistoryRows($crawler);
        $latest = $this->parseLatestTrade($crawler);

        if ($latest !== []) {
            $latestDate = $this->normalizeTradeDateKey($latest['date']);
            $hasLatestDate = false;

            foreach ($rows as $row) {
                if ($this->normalizeTradeDateKey($row['date']) === $latestDate) {
                    $hasLatestDate = true;

                    break;
                }
            }

            if (! $hasLatestDate) {
                array_unshift($rows, $latest);
                usort($rows, fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);
            }
        }

        return array_map(
            fn (array $row) => $this->stripHistoryTimestamp($row),
            $rows,
        );
    }

    private function fetchStockHistoryPage(string $isin): ?Crawler
    {
        $cacheKey = "uzse:history:{$isin}";
        Cache::forget($cacheKey);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->historyHeaders())
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->timeout(10)
                ->connectTimeout(5)
                ->get(self::BASE_URL.'/isu_infos/STK', [
                    'isu_cd' => $isin,
                    '_' => (string) microtime(true),
                ]);

            if ($response->failed()) {
                Log::error('Failed to fetch UZSE stock history', [
                    'isin' => $isin,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return new Crawler($response->body());
        } catch (ConnectionException|RequestException|\Throwable $e) {
            Log::error('Failed to fetch UZSE stock history', [
                'isin' => $isin,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}>
     */
    private function fetchStockHistoryRows(string $isin): array
    {
        $crawler = $this->fetchStockHistoryPage($isin);

        if ($crawler === null) {
            return [];
        }

        return $this->parseAllStockHistoryRows($crawler);
    }

    /**
     * Latest trade from today's session (summary block or intraday table).
     *
     * @return array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}
     */
    private function parseLatestTrade(Crawler $crawler): array
    {
        $summary = $this->parseLatestTradeSummary($crawler);

        if ($summary !== []) {
            return $summary;
        }

        return $this->parseIntradayLatestRow($crawler);
    }

    /**
     * @return array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}
     */
    private function parseLatestTradeSummary(Crawler $crawler): array
    {
        $date = null;

        $crawler->filter('div.text-left')->each(function (Crawler $node) use (&$date) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->text()));

            if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $text)) {
                $date = $text;
            }
        });

        $priceNodes = $crawler->filter('span.trd-price');

        if ($date === null || $priceNodes->count() === 0) {
            return [];
        }

        $price = $this->parsePrice($priceNodes->first()->text());

        if ($price <= 0) {
            return [];
        }

        $change = 0.0;
        $quantity = 0;
        $volume = 0.0;
        $tables = $crawler->filter('table');

        if ($tables->count() >= 2) {
            $cells = $tables->eq(1)->filter('tr')->eq(1)->filter('td');

            if ($cells->count() >= 3) {
                $change = $this->parseChange($cells->eq(0));
                $quantity = $this->parseQuantity($cells->eq(1)->text());
                $volume = $this->parseVolume($cells->eq(2)->text());
            }
        }

        $timestamp = $this->parseTradeDate($date);

        if ($timestamp === null) {
            return [];
        }

        return [
            'date' => $date,
            'price' => $price,
            'change' => $change,
            'quantity' => $quantity,
            'volume' => $volume,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * @return array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}
     */
    private function parseIntradayLatestRow(Crawler $crawler): array
    {
        $tables = $crawler->filter('table');

        if ($tables->count() < 4) {
            return [];
        }

        return $this->parseHistoryTableRow($tables->eq(3)->filter('tr')->eq(1));
    }

    /**
     * @return array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}
     */
    private function parseDailyHistoryLatestRow(Crawler $crawler): array
    {
        $tables = $crawler->filter('table');

        if ($tables->count() < 5) {
            return [];
        }

        return $this->parseHistoryTableRow($tables->eq(4)->filter('tr')->eq(1));
    }

    /**
     * @return array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}
     */
    private function parseHistoryTableRow(Crawler $row): array
    {
        $cells = $row->filter('td');

        if ($cells->count() < 5) {
            return [];
        }

        $dateText = trim($cells->eq(0)->text());
        $price = $this->parsePrice($cells->eq(1)->text());
        $timestamp = $this->parseTradeDate($dateText);

        if ($price <= 0 || $timestamp === null) {
            return [];
        }

        return [
            'date' => $dateText,
            'price' => $price,
            'change' => $this->parseChange($cells->eq(2)),
            'quantity' => $this->parseQuantity($cells->eq(3)->text()),
            'volume' => $this->parseVolume($cells->eq(4)->text()),
            'timestamp' => $timestamp,
        ];
    }

    private function normalizeTradeDateKey(string $date): string
    {
        $timestamp = $this->parseTradeDate($date);

        if ($timestamp === null) {
            return trim($date);
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @param  array{date: string, price: float, change: float, quantity: int, volume: float, timestamp: int}  $row
     * @return array{date: string, price: float, change: float, quantity: int, volume: float}
     */
    private function stripHistoryTimestamp(array $row): array
    {
        unset($row['timestamp']);

        return $row;
    }

    public function getIndex(): float
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->jsonHeaders())
                ->timeout(10)
                ->connectTimeout(5)
                ->get(self::BASE_URL.'/indices');
        } catch (ConnectionException|RequestException $e) {
            Log::error('Failed to fetch UZSE index', ['error' => $e->getMessage()]);

            return 0.0;
        }

        if ($response->failed()) {
            Log::error('Failed to fetch UZSE index', ['status' => $response->status()]);

            return 0.0;
        }

        $data = $response->json();

        return (float) ($data['last_index']['idx'] ?? 0);
    }

    private function parseAllStockHistoryRows(Crawler $crawler): array
    {
        $tables = $crawler->filter('table');

        if ($tables->count() < 5) {
            return [];
        }

        $rows = [];

        $tables->eq(4)->filter('tr')->each(function (Crawler $row) use (&$rows) {
            $parsed = $this->parseHistoryTableRow($row);

            if ($parsed !== []) {
                $rows[] = $parsed;
            }
        });

        usort($rows, fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);

        return $rows;
    }

    /**
     * UZSE history table prices are already in full soums (e.g. "6,398" → 6398).
     */
    private function parsePrice(string $text): float
    {
        return floatval(str_replace(',', '', trim($text)));
    }

    private function parseTradeDate(string $text): ?int
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:[,\s]+(\d{1,2}):(\d{2}))?/u', $text, $matches)) {
            return $this->buildTimestamp(
                (int) $matches[3],
                (int) $matches[2],
                (int) $matches[1],
                isset($matches[4]) ? (int) $matches[4] : 0,
                isset($matches[5]) ? (int) $matches[5] : 0,
            );
        }

        if (preg_match('/^(\d{1,2})\s+([а-яё]+)\s+(\d{4}),?\s*(\d{1,2}):(\d{2})/iu', $text, $matches)) {
            $month = $this->russianMonthToNumber($matches[2]);

            if ($month === null) {
                return null;
            }

            return $this->buildTimestamp(
                (int) $matches[3],
                $month,
                (int) $matches[1],
                (int) $matches[4],
                (int) $matches[5],
            );
        }

        if (preg_match('/^(\d{1,2})\s+([а-яё]+),?\s*(\d{1,2}):(\d{2})/iu', $text, $matches)) {
            $month = $this->russianMonthToNumber($matches[2]);

            if ($month === null) {
                return null;
            }

            return $this->buildTimestamp(
                (int) date('Y'),
                $month,
                (int) $matches[1],
                (int) $matches[3],
                (int) $matches[4],
            );
        }

        if (preg_match('/^(\d{1,2})\s+([а-яё]+)\s+(\d{4})$/iu', $text, $matches)) {
            $month = $this->russianMonthToNumber($matches[2]);

            if ($month === null) {
                return null;
            }

            return $this->buildTimestamp((int) $matches[3], $month, (int) $matches[1]);
        }

        return null;
    }

    private function buildTimestamp(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
    ): ?int {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return mktime($hour, $minute, 0, $month, $day, $year) ?: null;
    }

    private function russianMonthToNumber(string $month): ?int
    {
        $month = mb_strtolower(trim($month));

        $prefixes = [
            'январ' => 1,
            'феврал' => 2,
            'март' => 3,
            'апрел' => 4,
            'май' => 5,
            'мая' => 5,
            'июн' => 6,
            'июл' => 7,
            'август' => 8,
            'сентябр' => 9,
            'октябр' => 10,
            'ноябр' => 11,
            'декабр' => 12,
        ];

        foreach ($prefixes as $prefix => $number) {
            if (str_starts_with($month, $prefix)) {
                return $number;
            }
        }

        return null;
    }

    private function parseChange(Crawler $cell): float
    {
        $text = trim($cell->text());
        $value = floatval(str_replace([',', '▲', '▼', ' '], '', $text));
        $class = $cell->attr('class') ?? '';

        if ($class === '' && $cell->filter('span')->count() > 0) {
            $class = $cell->filter('span')->first()->attr('class') ?? '';
        }

        if (str_contains($class, 'price-down') || str_contains($text, '▼')) {
            return -abs($value);
        }

        if (str_contains($class, 'price-up') || str_contains($text, '▲')) {
            return abs($value);
        }

        return $value;
    }

    private function extractCompanyName(string $text): string
    {
        return trim(str_replace(['<', '>'], '', html_entity_decode($text)));
    }

    private function parseQuantity(string $text): int
    {
        return intval(str_replace(',', '', trim($text)));
    }

    private function parseVolume(string $text): float
    {
        return floatval(str_replace(',', '', trim($text)));
    }

    private function htmlHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/json',
            'User-Agent' => 'Mozilla/5.0',
        ];
    }

    private function historyHeaders(): array
    {
        return array_merge($this->htmlHeaders(), [
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ]);
    }

    private function jsonHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0',
        ];
    }
}
