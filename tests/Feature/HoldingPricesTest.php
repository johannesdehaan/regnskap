<?php

use App\Models\Holding;
use App\Models\User;
use App\Services\StockPriceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    // A Wednesday.
    Carbon::setTestNow(Carbon::parse('2026-11-25 16:45:00', 'Europe/Oslo'));

    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the price service reads the latest price and the currency from the chart api', function () {
    fakeYahooPrices(['EKSEMPEL.OL' => 12.34]);

    $quote = app(StockPriceService::class)->quote('EKSEMPEL.OL');

    expect($quote)->not->toBeNull()
        ->and($quote->price)->toBe(12.34)
        ->and($quote->currency)->toBe('NOK');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://query1.finance.yahoo.com/v8/finance/chart/EKSEMPEL.OL?interval=1d&range=1d'
        && $request->hasHeader('User-Agent', 'Mozilla/5.0'));
});

test('the price service encodes the ticker in the url', function () {
    fakeYahooPrices(['^EKSEMPEL' => 1000.5]);

    expect(app(StockPriceService::class)->quote('^EKSEMPEL')?->price)->toBe(1000.5);

    Http::assertSent(fn (Request $request): bool => str_starts_with(
        $request->url(),
        'https://query1.finance.yahoo.com/v8/finance/chart/%5EEKSEMPEL?',
    ));
});

test('the price service logs and returns null when no usable price comes back', function (mixed $response) {
    Log::spy();
    Http::fake(['query1.finance.yahoo.com/*' => $response]);

    expect(app(StockPriceService::class)->quote('EKSEMPEL.OL'))->toBeNull();

    Log::shouldHaveReceived('warning')->once();
})->with([
    'an unknown ticker' => fn () => Http::response(yahooNotFound(), 404),
    'too many requests' => fn () => Http::response('Too Many Requests', 429),
    'a server error' => fn () => Http::response('', 500),
    'a body that is not json' => fn () => Http::response('<html></html>'),
    'no result' => fn () => Http::response(['chart' => ['result' => null, 'error' => null]]),
    'no price' => fn () => Http::response(['chart' => ['result' => [['meta' => ['currency' => 'NOK']]]]]),
    'a price of zero' => fn () => Http::response(yahooChart('EKSEMPEL.OL', 0)),
    'a failed connection' => fn () => Http::failedConnection(),
]);

test('the price service does not ask yahoo about an empty ticker', function () {
    Http::fake();

    expect(app(StockPriceService::class)->quote('  '))->toBeNull();

    Http::assertNothingSent();
});

test('the command stores price, value and time on holdings with a ticker and leaves the rest', function () {
    $user = User::factory()->create();
    $stock = Holding::factory()->for($user)->create([
        'name' => 'Eksempel ASA',
        'ticker' => 'EKSEMPEL.OL',
        'price' => '10.0000',
        'quantity' => '3.500000',
        'value' => '35.00',
    ]);
    $otherUsersStock = Holding::factory()->create([
        'name' => 'Prøve ASA',
        'ticker' => 'PROVE.OL',
        'price' => null,
        'quantity' => '100.000000',
        'value' => '0.00',
    ]);
    $cash = Holding::factory()->for($user)->create([
        'name' => 'Kontanter',
        'ticker' => null,
        'price' => null,
        'quantity' => null,
        'value' => '250.00',
    ]);

    fakeYahooPrices(['EKSEMPEL.OL' => 12.3456, 'PROVE.OL' => 0.125]);

    $this->artisan('holdings:update-prices')
        ->expectsOutputToContain('2 oppdatert, 0 hoppet over.')
        ->assertSuccessful();

    $stock->refresh();
    $otherUsersStock->refresh();
    $cash->refresh();

    expect($stock->price)->toBe('12.3456')
        ->and($stock->value)->toBe('43.21')
        ->and($stock->quantity)->toBe('3.500000')
        ->and($stock->price_updated_at?->toJSON())->toBe('2026-11-25T15:45:00.000000Z')
        ->and($otherUsersStock->price)->toBe('0.1250')
        ->and($otherUsersStock->value)->toBe('12.50')
        ->and($otherUsersStock->price_updated_at?->toJSON())->toBe('2026-11-25T15:45:00.000000Z')
        ->and($cash->value)->toBe('250.00')
        ->and($cash->price)->toBeNull()
        ->and($cash->price_updated_at)->toBeNull();

    Http::assertSentCount(2);
});

test('the command skips a ticker that fails, updates the others and reports failure', function () {
    $failing = Holding::factory()->create([
        'ticker' => 'UKJENT.OL',
        'price' => '10.0000',
        'quantity' => '10.000000',
        'value' => '100.00',
    ]);
    $working = Holding::factory()->create([
        'ticker' => 'EKSEMPEL.OL',
        'price' => '10.0000',
        'quantity' => '10.000000',
        'value' => '100.00',
    ]);

    fakeYahooPrices(['EKSEMPEL.OL' => 20]);

    $this->artisan('holdings:update-prices')
        ->expectsOutputToContain('Fant ingen kurs for UKJENT.OL.')
        ->expectsOutputToContain('1 oppdatert, 1 hoppet over.')
        ->assertFailed();

    $failing->refresh();
    $working->refresh();

    expect($failing->price)->toBe('10.0000')
        ->and($failing->value)->toBe('100.00')
        ->and($failing->price_updated_at)->toBeNull()
        ->and($working->price)->toBe('20.0000')
        ->and($working->value)->toBe('200.00')
        ->and($working->price_updated_at)->not->toBeNull();
});

test('a holding without a quantity gets the price and keeps its value', function () {
    $holding = Holding::factory()->create([
        'ticker' => 'EKSEMPEL.OL',
        'price' => null,
        'quantity' => null,
        'value' => '500.00',
    ]);

    fakeYahooPrices(['EKSEMPEL.OL' => 12.34]);

    $this->artisan('holdings:update-prices')->assertSuccessful();

    $holding->refresh();

    expect($holding->price)->toBe('12.3400')
        ->and($holding->value)->toBe('500.00')
        ->and($holding->price_updated_at)->not->toBeNull();
});

test('a price in another currency than kroner is not stored', function (?string $currency, string $message) {
    $holding = Holding::factory()->create([
        'ticker' => 'UTLAND',
        'price' => '10.0000',
        'quantity' => '10.000000',
        'value' => '100.00',
    ]);

    fakeYahooPrices(['UTLAND' => 99.5], $currency);

    $this->artisan('holdings:update-prices')
        ->expectsOutputToContain($message)
        ->assertFailed();

    $holding->refresh();

    expect($holding->price)->toBe('10.0000')
        ->and($holding->value)->toBe('100.00')
        ->and($holding->price_updated_at)->toBeNull();
})->with([
    'dollars' => ['USD', 'Kursen for UTLAND er i USD, ikke NOK.'],
    'pence' => ['GBp', 'Kursen for UTLAND er i GBp, ikke NOK.'],
    'no currency' => [null, 'Kursen for UTLAND mangler valuta.'],
]);

test('two holdings with the same ticker share one request', function () {
    $first = Holding::factory()->create(['ticker' => 'EKSEMPEL.OL', 'quantity' => '1.000000']);
    $second = Holding::factory()->create(['ticker' => 'EKSEMPEL.OL', 'quantity' => '2.000000']);

    fakeYahooPrices(['EKSEMPEL.OL' => 50]);

    $this->artisan('holdings:update-prices')->assertSuccessful();

    expect($first->fresh()->value)->toBe('50.00')
        ->and($second->fresh()->value)->toBe('100.00');

    Http::assertSentCount(1);
});

test('the command does nothing when no holding has a ticker', function () {
    Http::fake();
    Holding::factory()->create(['ticker' => null]);

    $this->artisan('holdings:update-prices')
        ->expectsOutputToContain('Ingen beholdninger har ticker.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

// The app sleeps when nobody uses it, so the prices are asked for from outside.
test('the scheduler does not fetch prices', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'holdings:'));

    expect($commands->all())->toBe([]);
});

test('the holdings api requires a token for the price updates', function () {
    $holding = Holding::factory()->create(['ticker' => 'EKSEMPEL.OL']);

    $this->postJson('/api/holdings/update-prices')->assertUnauthorized();
    $this->postJson('/api/holdings/'.$holding->id.'/update-price')->assertUnauthorized();
});

test('holdings are stored, updated and listed with ticker, isin and price_updated_at', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $id = $this->withToken($token)
        ->postJson('/api/holdings', [
            'name' => 'Eksempel ASA',
            'value' => '1500.00',
            'price' => '10.00',
            'quantity' => '150',
            'type' => 'portefolje',
            'ticker' => 'eksempel.ol',
            'isin' => 'no0000000001',
            'price_updated_at' => '2026-11-24T16:45:00+01:00',
        ])
        ->assertCreated()
        ->assertJsonPath('ticker', 'EKSEMPEL.OL')
        ->assertJsonPath('isin', 'NO0000000001')
        ->assertJsonPath('price_updated_at', '2026-11-24T15:45:00.000000Z')
        ->json('id');

    $this->withToken($token)
        ->getJson('/api/holdings')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $id)
        ->assertJsonPath('0.ticker', 'EKSEMPEL.OL')
        ->assertJsonPath('0.isin', 'NO0000000001')
        ->assertJsonPath('0.price_updated_at', '2026-11-24T15:45:00.000000Z');

    $this->withToken($token)
        ->patchJson('/api/holdings/'.$id, [
            'ticker' => 'PROVE.OL',
            'price_updated_at' => '2026-11-25T08:00:00Z',
        ])
        ->assertOk()
        ->assertJsonPath('ticker', 'PROVE.OL')
        ->assertJsonPath('isin', 'NO0000000001')
        ->assertJsonPath('price_updated_at', '2026-11-25T08:00:00.000000Z')
        ->assertJsonPath('name', 'Eksempel ASA');

    $this->withToken($token)
        ->patchJson('/api/holdings/'.$id, [
            'ticker' => null,
            'isin' => null,
            'price_updated_at' => null,
        ])
        ->assertOk()
        ->assertJsonPath('ticker', null)
        ->assertJsonPath('isin', null)
        ->assertJsonPath('price_updated_at', null)
        ->assertJsonPath('price', '10.0000');

    Http::assertNothingSent();
});

test('a holding stored without a ticker returns the new fields as null', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/holdings', [
            'name' => 'Kontanter',
            'value' => '250.00',
            'type' => 'portefolje',
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'name', 'value', 'price', 'quantity', 'type', 'ticker', 'isin', 'price_updated_at'])
        ->assertJsonPath('ticker', null)
        ->assertJsonPath('isin', null)
        ->assertJsonPath('price_updated_at', null);
});

test('the holdings api rejects a malformed ticker, isin or time', function (string $field, mixed $value) {
    $user = User::factory()->create();
    $holding = Holding::factory()->for($user)->create(['ticker' => 'EKSEMPEL.OL', 'isin' => null]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/holdings', [
            'name' => 'Eksempel ASA',
            'value' => '100.00',
            'type' => 'portefolje',
            $field => $value,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    $this->withToken($token)
        ->patchJson('/api/holdings/'.$holding->id, [$field => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect($user->holdings()->count())->toBe(1)
        ->and($holding->fresh()->ticker)->toBe('EKSEMPEL.OL');
})->with([
    'a ticker with a space' => ['ticker', 'EKS EMPEL'],
    'a ticker with a slash' => ['ticker', 'EKSEMPEL/OL'],
    'a ticker that is too long' => ['ticker', str_repeat('A', 33)],
    'a ticker that is not a string' => ['ticker', ['EKSEMPEL.OL']],
    'an isin that is too short' => ['isin', 'NO000000001'],
    'an isin that is too long' => ['isin', 'NO00000000012'],
    'an isin without a country code' => ['isin', '000000000001'],
    'a time that is not a date' => ['price_updated_at', 'i går kveld'],
]);

test('update-prices refreshes the token users holdings and reports what was skipped', function () {
    $user = User::factory()->create();
    $stock = Holding::factory()->for($user)->create([
        'name' => 'Eksempel ASA',
        'ticker' => 'EKSEMPEL.OL',
        'price' => '10.0000',
        'quantity' => '100.000000',
        'value' => '1000.00',
    ]);
    $unknown = Holding::factory()->for($user)->create([
        'name' => 'Ukjent ASA',
        'ticker' => 'UKJENT.OL',
        'price' => '10.0000',
        'quantity' => '100.000000',
        'value' => '1000.00',
    ]);
    $cash = Holding::factory()->for($user)->create([
        'name' => 'Kontanter',
        'ticker' => null,
        'value' => '250.00',
    ]);
    $theirs = Holding::factory()->create([
        'ticker' => 'EKSEMPEL.OL',
        'price' => '10.0000',
        'quantity' => '100.000000',
        'value' => '1000.00',
    ]);

    fakeYahooPrices(['EKSEMPEL.OL' => 12.5]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/holdings/update-prices')
        ->assertOk()
        ->assertJsonCount(1, 'updated')
        ->assertJsonPath('updated.0.id', $stock->id)
        ->assertJsonPath('updated.0.ticker', 'EKSEMPEL.OL')
        ->assertJsonPath('updated.0.price', '12.5000')
        ->assertJsonPath('updated.0.value', '1250.00')
        ->assertJsonPath('updated.0.price_updated_at', '2026-11-25T15:45:00.000000Z')
        ->assertJsonPath('skipped', [[
            'id' => $unknown->id,
            'name' => 'Ukjent ASA',
            'ticker' => 'UKJENT.OL',
            'message' => 'Fant ingen kurs for UKJENT.OL.',
        ]]);

    expect($stock->fresh()->value)->toBe('1250.00')
        ->and($unknown->fresh()->value)->toBe('1000.00')
        ->and($cash->fresh()->value)->toBe('250.00')
        ->and($theirs->fresh()->price)->toBe('10.0000')
        ->and($theirs->fresh()->price_updated_at)->toBeNull();

    Http::assertSentCount(2);
});

test('update-prices returns empty lists when the user has no holdings with a ticker', function () {
    Http::fake();
    $user = User::factory()->create();
    Holding::factory()->for($user)->create(['ticker' => null]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/holdings/update-prices')
        ->assertOk()
        ->assertExactJson(['updated' => [], 'skipped' => []]);

    Http::assertNothingSent();
});

test('update-price refreshes one holding and returns it', function () {
    $user = User::factory()->create();
    $holding = Holding::factory()->for($user)->create([
        'ticker' => 'EKSEMPEL.OL',
        'price' => '10.0000',
        'quantity' => '100.000000',
        'value' => '1000.00',
    ]);
    $untouched = Holding::factory()->for($user)->create([
        'ticker' => 'PROVE.OL',
        'price' => '10.0000',
    ]);

    fakeYahooPrices(['EKSEMPEL.OL' => 12.5, 'PROVE.OL' => 99]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/holdings/'.$holding->id.'/update-price')
        ->assertOk()
        ->assertJsonPath('id', $holding->id)
        ->assertJsonPath('price', '12.5000')
        ->assertJsonPath('value', '1250.00')
        ->assertJsonPath('price_updated_at', '2026-11-25T15:45:00.000000Z');

    expect($holding->fresh()->value)->toBe('1250.00')
        ->and($untouched->fresh()->price)->toBe('10.0000');

    Http::assertSentCount(1);
});

test('update-price answers 422 when the holding has no ticker or no price is found', function () {
    $user = User::factory()->create();
    $cash = Holding::factory()->for($user)->create(['ticker' => null, 'value' => '250.00']);
    $unknown = Holding::factory()->for($user)->create([
        'ticker' => 'UKJENT.OL',
        'price' => '10.0000',
        'value' => '1000.00',
    ]);
    $token = $user->createToken('test')->plainTextToken;

    fakeYahooPrices([]);

    $this->withToken($token)
        ->postJson('/api/holdings/'.$cash->id.'/update-price')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ticker']);

    Http::assertNothingSent();

    $this->withToken($token)
        ->postJson('/api/holdings/'.$unknown->id.'/update-price')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Fant ingen kurs for UKJENT.OL.']);

    expect($cash->fresh()->value)->toBe('250.00')
        ->and($unknown->fresh()->price)->toBe('10.0000')
        ->and($unknown->fresh()->price_updated_at)->toBeNull();
});

test('update-price is forbidden on another users holding and 404 on a missing one', function () {
    Http::fake();
    $user = User::factory()->create();
    $theirs = Holding::factory()->create(['ticker' => 'EKSEMPEL.OL', 'price' => '10.0000']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/holdings/'.$theirs->id.'/update-price')
        ->assertForbidden();

    $this->withToken($token)
        ->postJson('/api/holdings/999999/update-price')
        ->assertNotFound();

    expect($theirs->fresh()->price)->toBe('10.0000');

    Http::assertNothingSent();
});

test('the investments page stores the ticker and shows when the price was updated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::investments.index')
        ->set('name', 'Eksempel ASA')
        ->set('value', '1500.00')
        ->set('quantity', '150')
        ->set('type', 'portefolje')
        ->set('ticker', 'eksempel.ol')
        ->set('isin', 'no0000000001')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('ticker', '')
        ->assertSet('isin', '')
        ->assertSee('EKSEMPEL.OL')
        ->assertSee('Kursen er ikke hentet ennå.');

    $holding = $user->holdings()->sole();

    expect($holding->ticker)->toBe('EKSEMPEL.OL')
        ->and($holding->isin)->toBe('NO0000000001')
        ->and($holding->price_updated_at)->toBeNull();

    $holding->update([
        'price' => '12.3400',
        'price_updated_at' => Carbon::parse('2026-11-24 16:45:00', 'Europe/Oslo'),
    ]);

    Livewire::test('pages::investments.index')
        ->assertSee('Kurs 12,34 kr')
        ->assertSee('oppdatert 24.11.2026 16:45')
        ->assertDontSee('Kursen er ikke hentet ennå.')
        ->call('edit', $holding->id)
        ->assertSet('ticker', 'EKSEMPEL.OL')
        ->assertSet('isin', 'NO0000000001')
        ->set('ticker', '')
        ->set('isin', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee('EKSEMPEL.OL');

    $holding->refresh();

    expect($holding->ticker)->toBeNull()
        ->and($holding->isin)->toBeNull()
        ->and($holding->price)->toBe('12.3400');
});

test('the investments page shows the price with two to four decimals', function (string $price, string $shown) {
    $user = User::factory()->create();
    Holding::factory()->for($user)->create([
        'ticker' => 'EKSEMPEL.OL',
        'price' => $price,
        'price_updated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::investments.index')
        ->assertSee('Kurs '.$shown.' kr');
})->with([
    ['12.0000', '12,00'],
    ['12.3400', '12,34'],
    ['12.3450', '12,345'],
    ['0.1234', '0,1234'],
    ['1234.5000', '1 234,50'],
]);

test('the investments page rejects a malformed ticker or isin', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::investments.index')
        ->set('name', 'Eksempel ASA')
        ->set('value', '1500.00')
        ->set('type', 'portefolje')
        ->set('ticker', 'EKS EMPEL')
        ->set('isin', 'NO123')
        ->call('save')
        ->assertHasErrors(['ticker', 'isin']);

    expect($user->holdings()->count())->toBe(0);
});

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
