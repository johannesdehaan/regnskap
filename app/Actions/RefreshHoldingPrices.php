<?php

namespace App\Actions;

use App\Models\Holding;
use App\Services\StockPriceService;
use Illuminate\Support\Facades\Log;

class RefreshHoldingPrices
{
    /**
     * Values are kept in kroner, so a price in another currency is not stored.
     */
    public const CURRENCY = 'NOK';

    public function __construct(private StockPriceService $prices) {}

    /**
     * Fetch the latest price for each holding that has a ticker, and store the
     * price, the value and the time. Holdings without a ticker are left alone.
     * A holding without a quantity gets the price, but keeps its value.
     *
     * @param  iterable<Holding>  $holdings
     * @return array{updated: list<Holding>, skipped: list<array{holding: Holding, message: string}>}
     */
    public function __invoke(iterable $holdings): array
    {
        $quotes = [];
        $updated = [];
        $skipped = [];

        foreach ($holdings as $holding) {
            $ticker = $holding->ticker;

            if ($ticker === null) {
                continue;
            }

            if (! array_key_exists($ticker, $quotes)) {
                $quotes[$ticker] = $this->prices->quote($ticker);
            }

            $quote = $quotes[$ticker];

            if ($quote === null) {
                $skipped[] = ['holding' => $holding, 'message' => 'Fant ingen kurs for '.$ticker.'.'];

                continue;
            }

            if ($quote->currency !== self::CURRENCY) {
                Log::warning('Kursen er ikke i norske kroner og ble ikke lagret.', [
                    'holding_id' => $holding->id,
                    'ticker' => $ticker,
                    'currency' => $quote->currency,
                ]);

                $skipped[] = ['holding' => $holding, 'message' => $quote->currency === null
                    ? 'Kursen for '.$ticker.' mangler valuta.'
                    : 'Kursen for '.$ticker.' er i '.$quote->currency.', ikke '.self::CURRENCY.'.'];

                continue;
            }

            $price = round($quote->price, 4);

            $holding->price = number_format($price, 4, '.', '');

            if ($holding->quantity !== null) {
                $holding->value = number_format(round($price * (float) $holding->quantity, 2), 2, '.', '');
            }

            $holding->price_updated_at = now()->startOfSecond();
            $holding->save();

            $updated[] = $holding;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }
}
