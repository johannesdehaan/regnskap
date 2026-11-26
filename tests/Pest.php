<?php

use App\Enums\BankConnectionStatus;
use App\Enums\Category;
use App\Models\BankConnection;
use App\Models\PendingBankTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A row in the snapshot of what the bank has reserved, on a made-up card account.
 *
 * @param  array<string, mixed>  $attributes
 */
function reservedBankRow(User $user, array $attributes = []): PendingBankTransaction
{
    $connection = BankConnection::query()->firstOrCreate(
        ['user_id' => $user->id],
        ['status' => BankConnectionStatus::Connected],
    );

    $account = $connection->accounts()->firstOrCreate(
        ['external_key' => 'reserved-card'],
        ['user_id' => $user->id, 'name' => 'Kredittkort', 'account_type' => 'creditCard', 'currency_code' => 'NOK'],
    );

    return $account->pendingTransactions()->create(array_merge([
        'user_id' => $user->id,
        'booked_on' => now('Europe/Oslo')->toDateString(),
        'amount' => '-100.00',
        'payee' => 'Kiwi',
        'suggested_category' => Category::Dagligvarer,
        'counts_as_food' => true,
        'last_seen_at' => now(),
    ], $attributes));
}

/**
 * Answer the chart API with made-up prices. A ticker that is not listed gets
 * the answer Yahoo gives for a symbol it does not know.
 *
 * @param  array<string, float|int>  $prices
 */
function fakeYahooPrices(array $prices, ?string $currency = 'NOK'): void
{
    Http::fake(function (Request $request) use ($prices, $currency) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ticker = rawurldecode(basename($path));

        if (! str_starts_with($path, '/v8/finance/chart/') || ! array_key_exists($ticker, $prices)) {
            return Http::response(yahooNotFound(), 404);
        }

        return Http::response(yahooChart($ticker, $prices[$ticker], $currency));
    });
}

/**
 * @return array<string, mixed>
 */
function yahooChart(string $ticker, float|int $price, ?string $currency = 'NOK'): array
{
    $meta = [
        'symbol' => $ticker,
        'exchangeName' => 'OSL',
        'instrumentType' => 'EQUITY',
        'regularMarketPrice' => $price,
        'dataGranularity' => '1d',
        'range' => '1d',
    ];

    if ($currency !== null) {
        $meta['currency'] = $currency;
    }

    return [
        'chart' => [
            'result' => [['meta' => $meta]],
            'error' => null,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function yahooNotFound(): array
{
    return [
        'chart' => [
            'result' => null,
            'error' => [
                'code' => 'Not Found',
                'description' => 'No data found, symbol may be delisted',
            ],
        ],
    ];
}
