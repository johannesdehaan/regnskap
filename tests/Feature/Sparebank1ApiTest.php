<?php

use App\Enums\BankConnectionStatus;
use App\Enums\PaymentMethod;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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

test('unauthenticated sparebank1 api requests receive 401', function () {
    $this->getJson('/api/sparebank1')->assertUnauthorized();
    $this->postJson('/api/sparebank1/sync')->assertUnauthorized();
    $this->patchJson('/api/sparebank1/accounts/1', [
        'sync_enabled' => true,
    ])->assertUnauthorized();
});

test('sparebank1 status is disconnected when the user has no bank connection', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/sparebank1')
        ->assertOk()
        ->assertExactJson([
            'connected' => false,
            'status' => 'disconnected',
            'status_label' => 'Ikke tilkoblet',
            'last_synced_at' => null,
            'last_sync_error' => null,
            'credentials_ready' => true,
            'accounts' => [],
        ]);

    Http::assertNothingSent();
});

test('sparebank1 status reports missing credentials without calling the bank', function () {
    config([
        'sparebank1.client_id' => null,
        'sparebank1.client_secret' => null,
    ]);

    $user = User::factory()->create();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/sparebank1')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('credentials_ready', false)
        ->assertJsonPath('accounts', []);

    Http::assertNothingSent();
});

test('sparebank1 status lists the authenticated users accounts and hides tokens', function () {
    $user = sparebank1ApiUser();
    $other = sparebank1ApiUser();
    $connection = $user->bankConnection;
    $connection->forceFill([
        'last_synced_at' => now(),
        'last_sync_error' => 'midlertidig feil',
    ])->save();

    $use = $connection->accounts()->where('name', 'Brukskonto')->first();
    $use->update([
        'sync_enabled' => true,
        'payment_method' => PaymentMethod::LonnskontoSparebank1,
    ]);

    $response = $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/sparebank1')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('status_label', 'Tilkoblet')
        ->assertJsonPath('last_synced_at', $connection->fresh()->last_synced_at->toISOString())
        ->assertJsonPath('last_sync_error', 'midlertidig feil')
        ->assertJsonPath('credentials_ready', true)
        ->assertJsonCount(3, 'accounts')
        ->assertJsonFragment([
            'id' => $use->id,
            'name' => 'Brukskonto',
            'account_number' => '12000000001',
            'display_number' => '1200.00.00001',
            'account_type' => 'deposit',
            'sync_enabled' => true,
            'payment_method' => 'Lønnskonto Sparebank 1',
        ])
        ->assertJsonFragment([
            'name' => 'Kredittkort',
            'account_number' => '12000000002',
            'display_number' => '1200.00.00002',
            'account_type' => 'creditCard',
            'sync_enabled' => false,
            'payment_method' => null,
        ]);

    $payload = $response->json();

    expect($payload)->not->toHaveKey('access_token')
        ->and($payload)->not->toHaveKey('refresh_token')
        ->and(json_encode($payload))->not->toContain('access-token-plaintext')
        ->and(json_encode($payload))->not->toContain('refresh-token-plaintext')
        ->and(json_encode($payload))->not->toContain('01017012345')
        ->and(collect($payload['accounts'])->pluck('id'))->not->toContain($other->bankConnection->accounts()->first()->id);

    Http::assertNothingSent();
});

test('sparebank1 status stays disconnected but still lists stored accounts', function () {
    $user = sparebank1ApiUser(BankConnectionStatus::Disconnected);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/sparebank1')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('status', 'disconnected')
        ->assertJsonPath('status_label', 'Ikke tilkoblet')
        ->assertJsonCount(3, 'accounts');
});

test('sparebank1 status keeps accounts when the connection must be renewed', function () {
    $user = sparebank1ApiUser(BankConnectionStatus::NeedsReauth);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/sparebank1')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('status', 'needs_reauth')
        ->assertJsonPath('status_label', 'Må logge inn med BankID på nytt')
        ->assertJsonCount(3, 'accounts');
});

test('sparebank1 sync imports transactions and returns counts', function () {
    $user = sparebank1ApiUser();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update([
        'sync_enabled' => true,
        'payment_method' => PaymentMethod::LonnskontoSparebank1,
    ]);

    sparebank1ApiFake([
        sparebank1ApiTransaction([
            'id' => 'txn-rema',
            'nonUniqueId' => 'nu-rema',
            'amount' => -189.5,
            'description' => 'REMA 1000 OSLO',
            'cleanedDescription' => 'Rema 1000',
        ]),
        [
            'id' => 'txn-broken',
            'description' => 'uten beløp',
        ],
    ]);

    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertExactJson([
            'imported' => 1,
            'updated' => 0,
            'skipped' => 1,
            'last_synced_at' => $user->bankConnection()->first()->last_synced_at->toISOString(),
        ]);

    expect($user->transactions()->where('payee', 'Rema 1000')->exists())->toBeTrue()
        ->and($user->bankConnection()->first()->last_sync_error)->toBeNull();

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 2);
});

test('sparebank1 sync returns 422 when the bank is not connected', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/sparebank1/sync')
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'SpareBank 1 er ikke tilkoblet.',
        ]);

    Http::assertNothingSent();
});

test('sparebank1 sync returns 422 when the connection needs bankid again', function () {
    $user = sparebank1ApiUser(BankConnectionStatus::NeedsReauth);
    $user->bankConnection->accounts()->update(['sync_enabled' => true]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/sparebank1/sync')
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'SpareBank 1 er ikke tilkoblet.',
        ]);

    Http::assertNothingSent();
});

test('sparebank1 sync returns 422 when no accounts are enabled', function () {
    $user = sparebank1ApiUser();

    sparebank1ApiFake();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/sparebank1/sync')
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'Velg minst én konto som skal synkes.',
        ]);

    expect($user->bankConnection()->first()->last_sync_error)->toBe('Velg minst én konto som skal synkes.')
        ->and($user->transactions()->count())->toBe(0);
});

test('sparebank1 sync returns 422 when sparebank 1 rejects the request', function () {
    $user = sparebank1ApiUser();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/personal/banking/accounts')) {
            return Http::response(sparebank1ApiAccounts());
        }

        return Http::response(['message' => 'unavailable'], 503);
    });

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/sparebank1/sync')
        ->assertStatus(422)
        ->assertJsonPath('message', 'SpareBank 1 svarte med HTTP 503.');

    expect($user->bankConnection()->first()->last_sync_error)->toBe('SpareBank 1 svarte med HTTP 503.');
});

test('a token can toggle sync and payment method on its own bank account', function () {
    $user = sparebank1ApiUser();
    $account = $user->bankConnection->accounts()->where('name', 'Brukskonto')->first();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/sparebank1/accounts/'.$account->id, [
            'sync_enabled' => true,
            'payment_method' => PaymentMethod::LonnskontoSparebank1->value,
            'name' => 'Skal ikke endres',
        ])
        ->assertOk()
        ->assertJsonPath('id', $account->id)
        ->assertJsonPath('name', 'Brukskonto')
        ->assertJsonPath('sync_enabled', true)
        ->assertJsonPath('payment_method', 'Lønnskonto Sparebank 1');

    $account->refresh();

    expect($account->sync_enabled)->toBeTrue()
        ->and($account->payment_method)->toBe(PaymentMethod::LonnskontoSparebank1)
        ->and($account->name)->toBe('Brukskonto');

    $this->withToken($token)
        ->patchJson('/api/sparebank1/accounts/'.$account->id, [
            'sync_enabled' => false,
        ])
        ->assertOk()
        ->assertJsonPath('sync_enabled', false)
        ->assertJsonPath('payment_method', 'Lønnskonto Sparebank 1');

    expect($account->fresh()->payment_method)->toBe(PaymentMethod::LonnskontoSparebank1);

    $this->withToken($token)
        ->patchJson('/api/sparebank1/accounts/'.$account->id, [
            'payment_method' => null,
        ])
        ->assertOk()
        ->assertJsonPath('sync_enabled', false)
        ->assertJsonPath('payment_method', null);

    expect($account->fresh()->sync_enabled)->toBeFalse()
        ->and($account->fresh()->payment_method)->toBeNull();
});

test('a token cannot update another users bank account', function () {
    $user = sparebank1ApiUser();
    $other = sparebank1ApiUser();
    $theirs = $other->bankConnection->accounts()->first();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->patchJson('/api/sparebank1/accounts/'.$theirs->id, [
            'sync_enabled' => true,
            'payment_method' => PaymentMethod::Wise->value,
        ])
        ->assertForbidden();

    expect($theirs->fresh()->sync_enabled)->toBeFalse()
        ->and($theirs->fresh()->payment_method)->toBeNull();
});

test('updating a missing bank account returns 404', function () {
    $user = sparebank1ApiUser();

    $this->withToken($user->createToken('test')->plainTextToken)
        ->patchJson('/api/sparebank1/accounts/999999', [
            'sync_enabled' => true,
        ])
        ->assertNotFound();
});

test('bank account updates require a known field and a valid payment method', function () {
    $user = sparebank1ApiUser();
    $account = $user->bankConnection->accounts()->first();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/sparebank1/accounts/'.$account->id, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sync_enabled']);

    $this->withToken($token)
        ->patchJson('/api/sparebank1/accounts/'.$account->id, [
            'payment_method' => 'Ikke en konto',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_method']);

    $this->withToken($token)
        ->patchJson('/api/sparebank1/accounts/'.$account->id, [
            'sync_enabled' => 'kanskje',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sync_enabled']);

    expect($account->fresh()->sync_enabled)->toBeFalse()
        ->and($account->fresh()->payment_method)->toBeNull();
});

/**
 * @param  list<array<string, mixed>>|null  $transactions
 */
function sparebank1ApiFake(?array $transactions = null): void
{
    Http::fake(function (Request $request) use ($transactions) {
        if (str_contains($request->url(), '/personal/banking/transactions')) {
            return Http::response([
                'transactions' => $transactions ?? [],
                'errors' => [],
            ]);
        }

        return Http::response(sparebank1ApiAccounts());
    });
}

/**
 * @return array<string, mixed>
 */
function sparebank1ApiAccounts(): array
{
    return [
        'accounts' => [
            [
                'key' => 'use-1',
                'name' => 'Brukskonto',
                'accountNumber' => '1200.00.00001',
                'type' => 'deposit',
                'currencyCode' => 'NOK',
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
function sparebank1ApiTransaction(array $overrides = []): array
{
    return array_merge([
        'id' => 'txn-1',
        'nonUniqueId' => 'nu-1',
        'date' => Carbon::parse('2026-11-10 15:00:00', 'Europe/Oslo')->getTimestamp() * 1000,
        'amount' => -10,
        'description' => 'Butikk',
        'cleanedDescription' => 'Butikk',
        'bookingStatus' => 'BOOKED',
        'accountKey' => 'use-1',
        'currencyCode' => 'NOK',
        'typeText' => 'Varer',
    ], $overrides);
}

function sparebank1ApiUser(BankConnectionStatus $status = BankConnectionStatus::Connected): User
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
        'status' => $status,
    ]);

    foreach (sparebank1ApiAccounts()['accounts'] as $account) {
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
