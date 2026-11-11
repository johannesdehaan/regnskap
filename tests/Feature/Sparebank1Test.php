<?php

use App\Actions\BuildFoodBudget;
use App\Enums\BankConnectionStatus;
use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Oslo'));

    config([
        'sparebank1.client_id' => 'test-client',
        'sparebank1.client_secret' => 'test-secret',
        'sparebank1.redirect_uri' => 'https://personlig-regnskap-production-huok0y.laravel.cloud/sparebank1/callback',
        'sparebank1.authorize_url' => 'https://api-auth.sparebank1.no/oauth/authorize',
        'sparebank1.token_url' => 'https://api-auth.sparebank1.no/oauth/token',
        'sparebank1.api_url' => 'https://api.sparebank1.no',
        'sparebank1.scope' => null,
    ]);

    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('guests are redirected from the bank settings and the callback', function () {
    $this->get(route('settings.bank'))->assertRedirect(route('login'));
    $this->get(route('sparebank1.callback'))->assertRedirect(route('login'));
});

test('the authorize url uses the documented oauth endpoint and never includes the client secret', function () {
    $user = User::factory()->create();

    $location = $this->actingAs($user)
        ->get(route('sparebank1.connect', ['fin_inst' => 'fid-smn']))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith('https://api-auth.sparebank1.no/oauth/authorize?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('test-client')
        ->and($query['redirect_uri'])->toBe('https://personlig-regnskap-production-huok0y.laravel.cloud/sparebank1/callback')
        ->and($query['finInst'])->toBe('fid-smn')
        ->and($query['state'])->toBeString()->not->toBeEmpty()
        ->and($query)->not->toHaveKey('client_secret')
        ->and($query)->not->toHaveKey('scope')
        ->and($location)->not->toContain('test-secret');
});

test('an optional scope is sent only when configured', function () {
    config(['sparebank1.scope' => 'openid accounts transactions']);

    $location = $this->actingAs(User::factory()->create())
        ->get(route('sparebank1.connect'))
        ->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid accounts transactions')
        ->and($query)->not->toHaveKey('finInst');
});

test('unknown banks and missing credentials never call sparebank 1', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('sparebank1.connect', ['fin_inst' => 'https://evil.example']))
        ->assertRedirect(route('settings.bank'));

    config(['sparebank1.client_id' => null, 'sparebank1.client_secret' => null]);

    $this->get(route('sparebank1.connect'))
        ->assertRedirect(route('settings.bank'));

    Http::assertNothingSent();
});

test('the callback exchanges the code, encrypts tokens and stores accounts without secrets or national identity numbers', function () {
    fakeSparebank1();

    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('sparebank1.connect', ['fin_inst' => 'fid-ostlandet']));
    $state = session('sparebank1.state');

    $this->get(route('sparebank1.callback', [
        'code' => 'one-time-code',
        'state' => $state,
    ]))->assertRedirect(route('settings.bank'));

    $connection = $user->bankConnection()->first();

    expect($connection)->not->toBeNull()
        ->and($connection->status)->toBe(BankConnectionStatus::Connected)
        ->and($connection->fin_inst)->toBe('fid-ostlandet')
        ->and($connection->access_token)->toBe('access-token-plaintext')
        ->and($connection->refresh_token)->toBe('refresh-token-plaintext')
        ->and($connection->accounts)->toHaveCount(3);

    expect(abs($connection->access_token_expires_at->diffInSeconds(now()->addSeconds(540))))->toBeLessThan(5)
        ->and(abs($connection->refresh_token_expires_at->diffInSeconds(now()->addDays(30))))->toBeLessThan(5);

    $stored = DB::table('bank_connections')->where('user_id', $user->id)->first();
    $accounts = DB::table('bank_accounts')->where('user_id', $user->id)->get()->toJson();

    expect($stored->access_token)->not->toBe('access-token-plaintext')
        ->and($stored->access_token)->not->toContain('access-token-plaintext')
        ->and($stored->refresh_token)->not->toContain('refresh-token-plaintext')
        ->and($stored->access_token)->not->toContain('test-secret')
        ->and($accounts)->not->toContain('01017012345')
        ->and($accounts)->not->toContain('test-secret');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/oauth/token')) {
            return false;
        }

        return $request->method() === 'POST'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'one-time-code'
            && $request['client_id'] === 'test-client'
            && $request['client_secret'] === 'test-secret'
            && $request['redirect_uri'] === 'https://personlig-regnskap-production-huok0y.laravel.cloud/sparebank1/callback'
            && ! str_contains($request->url(), 'test-secret');
    });

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/personal/banking/accounts')
            && $request->hasHeader('Accept', 'application/vnd.sparebank1.v1+json; charset=utf-8')
            && $request->hasHeader('Authorization', 'Bearer access-token-plaintext');
    });
});

test('a mismatched oauth state does not exchange the code', function () {
    fakeSparebank1();

    $this->actingAs(User::factory()->create())
        ->get(route('sparebank1.callback', ['code' => 'one-time-code', 'state' => 'nope']))
        ->assertRedirect(route('settings.bank'));

    expect(BankConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('bank settings lists accounts and saves which ones sync to which payment method', function () {
    $user = connectedUser();
    $this->actingAs($user);

    Livewire::test('pages::settings.bank')
        ->assertSee('Tilkoblet')
        ->assertSee('Brukskonto')
        ->assertSee('https://personlig-regnskap-production-huok0y.laravel.cloud/sparebank1/callback')
        ->set('accountRows.0.sync_enabled', true)
        ->set('accountRows.0.payment_method', PaymentMethod::LonnskontoSparebank1->value)
        ->call('saveAccounts')
        ->assertHasNoErrors();

    $account = $user->bankConnection->accounts()->where('name', 'Brukskonto')->first();

    expect($account->sync_enabled)->toBeTrue()
        ->and($account->payment_method)->toBe(PaymentMethod::LonnskontoSparebank1);
});

test('account choices cannot be changed for another user', function () {
    $user = connectedUser();
    $other = connectedUser();
    $otherAccount = $other->bankConnection->accounts()->first();

    $this->actingAs($user);

    Livewire::test('pages::settings.bank')
        ->set('accountRows', [[
            'id' => $otherAccount->id,
            'name' => 'Brukskonto',
            'number' => '',
            'type' => 'deposit',
            'sync_enabled' => true,
            'payment_method' => PaymentMethod::Wise->value,
        ]])
        ->call('saveAccounts');

    expect($otherAccount->fresh()->sync_enabled)->toBeFalse()
        ->and($otherAccount->fresh()->payment_method)->toBeNull();
});

test('sync imports booked purchases through the food rules and keeps transfers and card payments out of consumption', function () {
    $user = connectedUser();
    $use = $user->bankConnection->accounts()->where('external_key', 'use-1')->first();
    $use->update([
        'sync_enabled' => true,
        'payment_method' => PaymentMethod::LonnskontoSparebank1,
    ]);

    fakeSparebank1(transactions: [
        bankTransaction([
            'id' => 'txn-rema',
            'nonUniqueId' => 'nu-rema',
            'amount' => -189.5,
            'description' => 'REMA 1000 OSLO',
            'cleanedDescription' => 'Rema 1000',
            'bookingStatus' => 'BOOKED',
            'accountKey' => 'use-1',
            'typeText' => 'Varer',
        ]),
        bankTransaction([
            'id' => 'txn-book',
            'nonUniqueId' => 'nu-book',
            'amount' => -40,
            'description' => 'Bokhandel Sentrum',
            'cleanedDescription' => 'Bokhandel Sentrum',
            'bookingStatus' => 'BOOKED',
            'accountKey' => 'use-1',
        ]),
        bankTransaction([
            'id' => 'txn-own',
            'nonUniqueId' => 'nu-own',
            'amount' => -200,
            'description' => 'Overføring',
            'cleanedDescription' => 'Overføring',
            'remoteAccountNumber' => '1200.00.00003',
            'bookingStatus' => 'BOOKED',
            'accountKey' => 'use-1',
        ]),
        bankTransaction([
            'id' => 'txn-kari',
            'nonUniqueId' => 'nu-kari',
            'amount' => -15,
            'description' => 'Overføring til Kari',
            'cleanedDescription' => 'Overføring til Kari',
            'remoteAccountNumber' => '99999999999',
            'bookingStatus' => 'BOOKED',
            'accountKey' => 'use-1',
        ]),
        bankTransaction([
            'id' => 'txn-card',
            'nonUniqueId' => 'nu-card',
            'amount' => -500,
            'description' => 'Innbetaling kredittkort',
            'cleanedDescription' => 'Innbetaling kredittkort',
            'bookingStatus' => 'BOOKED',
            'accountKey' => 'use-1',
            'typeText' => 'Innbetaling',
        ]),
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.bank')
        ->call('syncNow')
        ->assertSet('bankError', null);

    expect($user->transactions()->count())->toBe(5);

    $rema = $user->transactions()->where('external_id', 'txn-rema')->first();
    $book = $user->transactions()->where('external_id', 'txn-book')->first();
    $own = $user->transactions()->where('external_id', 'txn-own')->first();
    $kari = $user->transactions()->where('external_id', 'txn-kari')->first();
    $card = $user->transactions()->where('external_id', 'txn-card')->first();

    expect($rema->category)->toBe(Category::Dagligvarer)
        ->and($rema->needs_review)->toBeFalse()
        ->and($rema->exclude_from_budget)->toBeFalse()
        ->and($rema->payment_method)->toBe(PaymentMethod::LonnskontoSparebank1)
        ->and($rema->amount)->toBe('189.50')
        ->and($rema->booked_on->toDateString())->toBe('2026-11-10');

    expect($book->category)->toBeNull()
        ->and($book->needs_review)->toBeTrue();

    expect($own->exclude_from_budget)->toBeTrue()
        ->and($own->category)->toBeNull()
        ->and($own->needs_review)->toBeFalse();

    expect($card->exclude_from_budget)->toBeTrue()
        ->and($card->needs_review)->toBeFalse();

    expect($kari->exclude_from_budget)->toBeFalse()
        ->and($kari->needs_review)->toBeTrue();

    $inbox = $user->transactions()->inbox()->pluck('payee');

    expect($inbox)->toContain('Bokhandel Sentrum')
        ->and($inbox)->toContain('Overføring til Kari')
        ->and($inbox)->not->toContain('Rema 1000')
        ->and($inbox)->not->toContain('Overføring')
        ->and($inbox)->not->toContain('Innbetaling kredittkort');

    $budget = app(BuildFoodBudget::class)($user);

    expect($budget['spent'])->toBe('189.50')
        ->and($budget['pending_review'])->toBe('55.00');

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->transactions()->count())->toBe(5);
});

test('a rotated transaction id does not create a duplicate', function () {
    $user = connectedUser();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);

    fakeSparebank1(transactions: [
        bankTransaction([
            'id' => 'encrypted-first',
            'nonUniqueId' => 'stable-row',
            'amount' => -80,
            'cleanedDescription' => 'Kiwi',
            'description' => 'KIWI',
        ]),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $user->transactions()->first()->update(['category' => Category::Bil]);

    fakeSparebank1(transactions: [
        bankTransaction([
            'id' => 'encrypted-second',
            'nonUniqueId' => 'stable-row',
            'amount' => -80,
            'cleanedDescription' => 'Kiwi',
            'description' => 'KIWI',
        ]),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->transactions()->count())->toBe(1)
        ->and($user->transactions()->first()->external_id)->toBe('encrypted-second')
        ->and($user->transactions()->first()->category)->toBe(Category::Bil);
});

test('a reserved amount is updated in place when it is booked', function () {
    $user = connectedUser();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);

    fakeSparebank1(transactions: [
        bankTransaction([
            'id' => 'pending-id',
            'nonUniqueId' => 'reservation-1',
            'amount' => -62.4,
            'cleanedDescription' => 'Meny',
            'description' => 'MENY RESERVASJON',
            'bookingStatus' => 'PENDING',
        ]),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->transactions()->count())->toBe(1)
        ->and($user->transactions()->first()->booking_status)->toBe(BookingStatus::Pending)
        ->and($user->transactions()->first()->category)->toBe(Category::Dagligvarer);

    fakeSparebank1(transactions: [
        bankTransaction([
            'id' => 'booked-id',
            'nonUniqueId' => 'different-non-unique',
            'amount' => -62.4,
            'cleanedDescription' => 'Meny',
            'description' => 'MENY',
            'bookingStatus' => 'BOOKED',
            'date' => bankMillis('2026-11-11 09:00:00'),
        ]),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $transaction = $user->transactions()->first();

    expect($user->transactions()->count())->toBe(1)
        ->and($transaction->external_id)->toBe('booked-id')
        ->and($transaction->booking_status)->toBe(BookingStatus::Booked)
        ->and($transaction->category)->toBe(Category::Dagligvarer)
        ->and($transaction->amount)->toBe('62.40');
});

test('an expired access token is refreshed and the new token is stored encrypted', function () {
    $user = connectedUser();
    $connection = $user->bankConnection;
    $connection->forceFill([
        'access_token_expires_at' => now()->subMinute(),
        'refresh_token_expires_at' => now()->addDays(10),
    ])->save();
    $connection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);

    fakeSparebank1([
        'access_token' => 'refreshed-access-token',
        'refresh_token' => 'refreshed-refresh-token',
        'expires_in' => 600,
    ], [
        bankTransaction([
            'id' => 'after-refresh',
            'nonUniqueId' => 'after-refresh-nu',
            'amount' => -10,
            'cleanedDescription' => 'Rema 1000',
        ]),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $connection->refresh();

    expect($connection->access_token)->toBe('refreshed-access-token')
        ->and($connection->refresh_token)->toBe('refreshed-refresh-token')
        ->and($user->transactions()->where('external_id', 'after-refresh')->exists())->toBeTrue();

    $stored = DB::table('bank_connections')->where('id', $connection->id)->value('access_token');

    expect($stored)->not->toContain('refreshed-access-token');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-token-plaintext'
        && ! str_contains($request->url(), 'test-secret'));
});

test('an invalid refresh token asks for bankid again', function () {
    $user = connectedUser();
    $user->bankConnection->forceFill([
        'access_token_expires_at' => now()->subMinute(),
    ])->save();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        return Http::response(['accounts' => [], 'transactions' => []], 200);
    });

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertFailed();

    $connection = $user->bankConnection()->first();

    expect($connection->status)->toBe(BankConnectionStatus::NeedsReauth)
        ->and($connection->access_token)->toBeNull()
        ->and($connection->refresh_token)->toBeNull()
        ->and($connection->last_sync_error)->toContain('BankID');
});

test('disconnect deletes the tokens', function () {
    $user = connectedUser();
    $this->actingAs($user);

    Livewire::test('pages::settings.bank')
        ->call('disconnect')
        ->assertSet('statusLabel', 'Ikke tilkoblet');

    $connection = $user->bankConnection()->first();

    expect($connection->status)->toBe(BankConnectionStatus::Disconnected)
        ->and($connection->access_token)->toBeNull()
        ->and($connection->refresh_token)->toBeNull()
        ->and(DB::table('bank_connections')->where('id', $connection->id)->value('access_token'))->toBeNull();
});

test('sparebank1 sync is scheduled every six hours', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'sparebank1:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 */6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('a missing expires_in falls back to the documented ten minute access token', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Http::response([
                'access_token' => 'access-token-plaintext',
                'refresh_token' => 'refresh-token-plaintext',
                'token_type' => 'Bearer',
            ]);
        }

        return Http::response(sparebank1Accounts());
    });

    $user = User::factory()->create();
    $this->actingAs($user);
    $this->get(route('sparebank1.connect'));

    $this->get(route('sparebank1.callback', [
        'code' => 'one-time-code',
        'state' => session('sparebank1.state'),
    ]))->assertRedirect(route('settings.bank'));

    expect(abs($user->bankConnection->access_token_expires_at->diffInSeconds(now()->addSeconds(540))))->toBeLessThan(5);
});

function fakeSparebank1(array $token = [], ?array $transactions = null): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();

    Http::fake(function (Request $request) use ($token, $transactions) {
        if (str_contains($request->url(), '/oauth/token')) {
            return Http::response(array_merge([
                'access_token' => 'access-token-plaintext',
                'refresh_token' => 'refresh-token-plaintext',
                'token_type' => 'Bearer',
                'expires_in' => 600,
            ], $token));
        }

        if (str_contains($request->url(), '/personal/banking/transactions')) {
            return Http::response([
                'transactions' => $transactions ?? [],
                'errors' => [],
            ]);
        }

        return Http::response(sparebank1Accounts());
    });
}

/**
 * @return array<string, mixed>
 */
function sparebank1Accounts(): array
{
    return [
        'accounts' => [
            [
                'key' => 'use-1',
                'name' => 'Brukskonto',
                'accountNumber' => '1200.00.00001',
                'type' => 'deposit',
                'currencyCode' => 'NOK',
                'owner' => [
                    'name' => 'Test Testesen',
                    'ssnOrOrgNumber' => '01017012345',
                ],
            ],
            [
                'key' => 'card-1',
                'name' => 'Kredittkort',
                'accountNumber' => '12000000002',
                'type' => 'creditCard',
                'currencyCode' => 'NOK',
            ],
            [
                'key' => 'save-1',
                'name' => 'Sparekonto',
                'accountNumber' => '12000000003',
                'type' => 'savings',
                'currencyCode' => 'NOK',
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bankTransaction(array $overrides = []): array
{
    return array_merge([
        'id' => 'txn-1',
        'nonUniqueId' => 'nu-1',
        'date' => bankMillis('2026-11-10 15:00:00'),
        'amount' => -10,
        'description' => 'Butikk',
        'cleanedDescription' => 'Butikk',
        'bookingStatus' => 'BOOKED',
        'accountKey' => 'use-1',
        'currencyCode' => 'NOK',
        'typeText' => 'Varer',
    ], $overrides);
}

function bankMillis(string $date): int
{
    return Carbon::parse($date, 'Europe/Oslo')->getTimestamp() * 1000;
}

function connectedUser(): User
{
    $user = User::factory()->create();

    $connection = BankConnection::query()->create([
        'user_id' => $user->id,
        'fin_inst' => 'fid-smn',
        'access_token' => 'access-token-plaintext',
        'refresh_token' => 'refresh-token-plaintext',
        'token_type' => 'Bearer',
        'access_token_expires_at' => now()->addMinutes(8),
        'refresh_token_expires_at' => now()->addDays(20),
        'status' => BankConnectionStatus::Connected,
    ]);

    foreach (sparebank1Accounts()['accounts'] as $account) {
        $connection->accounts()->create([
            'user_id' => $user->id,
            'external_key' => $account['key'],
            'name' => $account['name'],
            'account_number' => preg_replace('/\D+/', '', $account['accountNumber']),
            'account_type' => $account['type'],
            'currency_code' => 'NOK',
            'sync_enabled' => false,
        ]);
    }

    return $user->fresh();
}
