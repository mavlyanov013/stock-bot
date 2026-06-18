<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->htmlHeaders())
                ->timeout(10)
                ->connectTimeout(5)
                ->get(self::BASE_URL.'/isu_infos/STK', ['isu_cd' => $isin]);

            if ($response->failed()) {
                Log::error('Failed to fetch UZSE stock history', [
                    'isin' => $isin,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $this->parseStockHistory(new Crawler($response->body()));
        } catch (ConnectionException|RequestException|\Throwable $e) {
            Log::error('Failed to fetch UZSE stock history', [
                'isin' => $isin,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

    private function parseStockHistory(Crawler $crawler): array
    {
        $tables = $crawler->filter('table');

        if ($tables->count() < 5) {
            return [];
        }

        $rows = $tables->eq(4)->filter('tr');
        $latestRow = null;
        $latestTimestamp = null;

        $rows->each(function (Crawler $row) use (&$latestRow, &$latestTimestamp) {
            $cells = $row->filter('td');

            if ($cells->count() < 5) {
                return;
            }

            $price = $this->parsePrice($cells->eq(1)->text());

            if ($price <= 0) {
                return;
            }

            $timestamp = $this->parseTradeDate(trim($cells->eq(0)->text()));

            if ($timestamp === null) {
                return;
            }

            if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latestRow = $row;
            }
        });

        if ($latestRow === null) {
            return [];
        }

        $cells = $latestRow->filter('td');

        return [
            'date' => trim($cells->eq(0)->text()),
            'price' => $this->parsePrice($cells->eq(1)->text()),
            'change' => $this->parseChange($cells->eq(2)),
            'quantity' => $this->parseQuantity($cells->eq(3)->text()),
            'volume' => $this->parseVolume($cells->eq(4)->text()),
        ];
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

        if (str_contains($class, 'price-down')) {
            return -abs($value);
        }

        if (str_contains($class, 'price-up')) {
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

    private function jsonHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0',
        ];
    }
}
