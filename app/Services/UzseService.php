<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class UzseService
{
    private const BASE_URL = 'https://uzse.uz';

    public function getSecurities(): array
    {
        $securities = [];

        foreach ($this->getTradeResultsRows() as $row) {
            $securities[$row['symbol']] = $row['company_name'];
        }

        return $securities;
    }

    public function getPrices(): array
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Accept' => 'text/html',
                    'User-Agent' => 'Mozilla/5.0',
                ])
                ->timeout(30)
                ->get(self::BASE_URL.'/trade_results');

            if ($response->failed()) {
                Log::error('Failed to fetch UZSE trade results', ['status' => $response->status()]);

                return [];
            }

            $rows = $this->parseTradeResultsRows(new Crawler($response->body()));

            return array_map(fn (array $row) => [
                'symbol' => $row['symbol'],
                'price' => $row['price'],
                'change' => 0.0,
                'quantity' => $row['quantity'],
                'volume' => $row['volume'],
            ], $rows);
        } catch (ConnectionException|RequestException|\Throwable $e) {
            Log::error('Failed to fetch UZSE prices', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getIndex(): float
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->jsonHeaders())
                ->timeout(15)
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

    private function getTradeResultsRows(): array
    {
        $crawler = $this->fetchTradeResultsCrawler();

        if ($crawler === null) {
            return [];
        }

        return $this->parseTradeResultsRows($crawler);
    }

    private function parseTradeResultsRows(Crawler $crawler): array
    {
        $rows = [];
        $seen = [];

        $crawler->filter('table tbody tr')->each(function (Crawler $row) use (&$rows, &$seen) {
            $cells = $row->filter('td');
            if ($cells->count() < 8) {
                return;
            }

            $symbol = $this->extractSymbol($cells->eq(2)->text());
            if ($symbol === '' || isset($seen[$symbol])) {
                return;
            }

            $seen[$symbol] = true;

            $rows[] = [
                'symbol' => $symbol,
                'company_name' => $this->extractCompanyName($cells->eq(3)->text()),
                'price' => floatval(trim($cells->eq(7)->text())),
                'quantity' => $this->parseQuantity($cells->eq(8)->text()),
                'volume' => $this->parseVolume($cells->eq(9)->text()),
            ];
        });

        return $rows;
    }

    private function fetchTradeResultsCrawler(): ?Crawler
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Accept' => 'text/html',
                    'User-Agent' => 'Mozilla/5.0',
                ])
                ->timeout(15)
                ->get(self::BASE_URL.'/trade_results');
        } catch (ConnectionException|RequestException $e) {
            Log::error('Failed to fetch UZSE trade results', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::error('Failed to fetch UZSE trade results', ['status' => $response->status()]);

            return null;
        }

        return new Crawler($response->body());
    }

    private function extractSymbol(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text));

        return $parts ? (string) end($parts) : '';
    }

    private function extractCompanyName(string $text): string
    {
        return trim(str_replace(['<', '>'], '', $text));
    }

    private function parseQuantity(string $text): int
    {
        return intval(str_replace(',', '', trim($text)));
    }

    private function parseVolume(string $text): float
    {
        $cleaned = str_replace(',', '', str_replace('UZS ', '', trim($text)));

        return floatval($cleaned);
    }

    private function jsonHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0',
        ];
    }
}
