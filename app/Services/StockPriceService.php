<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StockPriceService
{
    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const TIMEOUT_SECONDS = 10;

    /**
     * The latest price for a ticker in Yahoo format, for example EQNR.OL.
     * Returns null, and logs why, when no usable price came back.
     */
    public function quote(string $ticker): ?StockQuote
    {
        $ticker = trim($ticker);

        if ($ticker === '') {
            return null;
        }

        try {
            $response = Http::withUserAgent('Mozilla/5.0')
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::CHART_URL.rawurlencode($ticker), [
                    'interval' => '1d',
                    'range' => '1d',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Fikk ikke kontakt med Yahoo Finance.', [
                'ticker' => $ticker,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Yahoo Finance svarte med feil.', [
                'ticker' => $ticker,
                'status' => $response->status(),
            ]);

            return null;
        }

        $meta = $response->json('chart.result.0.meta');
        $meta = is_array($meta) ? $meta : [];
        $price = $meta['regularMarketPrice'] ?? null;

        if (! is_numeric($price) || (float) $price <= 0) {
            Log::warning('Yahoo Finance returnerte ingen kurs.', [
                'ticker' => $ticker,
            ]);

            return null;
        }

        $currency = $meta['currency'] ?? null;

        return new StockQuote(
            $ticker,
            (float) $price,
            is_string($currency) && $currency !== '' ? $currency : null,
        );
    }
}
