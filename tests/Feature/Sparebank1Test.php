<?php

use App\Actions\BuildFoodBudget;
use App\Enums\BankConnectionStatus;
use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\BankConnection;
use App\Models\User;
use App\Services\Sparebank1\Sparebank1TransactionParser;
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

test('a reserved purchase becomes one transaction when the bank books it', function () {
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

    expect($user->transactions()->count())->toBe(0)
        ->and($user->pendingBankTransactions()->sole()->payee)->toBe('Meny');

    $booked = [
        bankTransaction([
            'id' => 'booked-id',
            'nonUniqueId' => 'different-non-unique',
            'amount' => -62.4,
            'cleanedDescription' => 'Meny',
            'description' => 'MENY',
            'bookingStatus' => 'BOOKED',
            'date' => bankMillis('2026-11-11 09:00:00'),
        ]),
    ];

    fakeSparebank1(transactions: $booked);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $transaction = $user->transactions()->sole();

    expect($transaction->external_id)->toBe('booked-id')
        ->and($transaction->booking_status)->toBe(BookingStatus::Booked)
        ->and($transaction->category)->toBe(Category::Dagligvarer)
        ->and($transaction->amount)->toBe('62.40')
        ->and($transaction->booked_on->toDateString())->toBe('2026-11-11')
        ->and($user->pendingBankTransactions()->count())->toBe(0);

    fakeSparebank1(transactions: $booked);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->transactions()->count())->toBe(1);
});

test('reserved rows are stored only in the snapshot, and every sync replaces it', function () {
    $user = connectedUserWithCard();
    $token = $user->createToken('test')->plainTextToken;
    $first = now();

    fakeSparebank1(transactions: [
        cardPurchase([
            'id' => 'slot-0',
            'nonUniqueId' => 'slot-0',
            'amount' => -74.9,
            'description' => 'KIWI 123 SENTRUM',
            'cleanedDescription' => 'Kiwi',
            'bookingStatus' => 'PENDING',
        ]),
        cardPurchase([
            'id' => 'slot-1',
            'nonUniqueId' => 'slot-1',
            'bookingStatus' => 'PENDING',
        ]),
        cardPurchase([
            'id' => 'slot-2',
            'nonUniqueId' => 'slot-2',
            'amount' => 50,
            'description' => 'REMA 1000 RETUR',
            'cleanedDescription' => 'Rema 1000',
            'remoteAccountNumber' => '9999.00.00009',
            'bookingStatus' => 'PENDING',
        ]),
    ]);

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('pending', 3);

    $card = $user->bankConnection->accounts()->where('external_key', 'card-1')->sole();
    $rows = $user->pendingBankTransactions()->orderBy('id')->get();
    $firstIds = $rows->modelKeys();

    expect($user->transactions()->count())->toBe(0)
        ->and($rows)->toHaveCount(3)
        ->and($rows[0]->only([
            'bank_account_id', 'external_id', 'amount', 'payee', 'note', 'type_text',
            'remote_account_number', 'suggested_category', 'counts_as_food',
        ]))->toBe([
            'bank_account_id' => $card->id,
            'external_id' => 'slot-0',
            'amount' => '-74.90',
            'payee' => 'Kiwi',
            'note' => "KIWI 123 SENTRUM\nKredittkort",
            'type_text' => 'Kredittkort',
            'remote_account_number' => null,
            'suggested_category' => Category::Dagligvarer,
            'counts_as_food' => true,
        ])
        ->and($rows[0]->booked_on->toDateString())->toBe('2026-11-10')
        ->and($rows[0]->last_seen_at->equalTo($first))->toBeTrue()
        ->and($rows[1]->payee)->toBe('Bokhandel Sentrum')
        ->and($rows[1]->amount)->toBe('-349.00')
        ->and($rows[1]->suggested_category)->toBeNull()
        ->and($rows[1]->counts_as_food)->toBeFalse()
        ->and($rows[2]->payee)->toBe('Rema 1000')
        ->and($rows[2]->amount)->toBe('50.00')
        ->and($rows[2]->remote_account_number)->toBe('99990000009')
        ->and($rows[2]->suggested_category)->toBe(Category::Dagligvarer)
        ->and($rows[2]->counts_as_food)->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-11-11 18:00:00', 'Europe/Oslo'));

    fakeSparebank1(transactions: [
        cardPurchase([
            'id' => 'slot-0',
            'nonUniqueId' => 'slot-0',
            'amount' => -129,
            'description' => 'JERNVAREHANDEL',
            'cleanedDescription' => 'Jernvarehandel',
            'bookingStatus' => 'PENDING',
        ]),
    ]);

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('pending', 1);

    $row = $user->pendingBankTransactions()->sole();

    expect($user->transactions()->count())->toBe(0)
        ->and($firstIds)->not->toContain($row->id)
        ->and($row->external_id)->toBe('slot-0')
        ->and($row->payee)->toBe('Jernvarehandel')
        ->and($row->amount)->toBe('-129.00')
        ->and($row->last_seen_at->equalTo(now()))->toBeTrue();

    fakeSparebank1(transactions: []);

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('pending', 0);

    expect($user->pendingBankTransactions()->count())->toBe(0)
        ->and($user->transactions()->count())->toBe(0);
});

test('a reserved id that the bank hands to new transactions never overwrites a transaction', function () {
    $user = connectedUser();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);
    $token = $user->createToken('test')->plainTextToken;

    fakeSparebank1(transactions: [
        bankTransaction([
            'id' => 'slot-0',
            'nonUniqueId' => 'slot-0',
            'amount' => -80,
            'description' => 'KIWI',
            'cleanedDescription' => 'Kiwi',
        ]),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $kiwi = $user->transactions()->sole();

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$kiwi->id, ['category' => 'Bil', 'note' => 'Kaffe på turen'])
        ->assertOk();

    $untouched = $kiwi->refresh()->getAttributes();

    $reserved = [
        ['amount' => 250, 'text' => 'Straksbetaling', 'stored' => '250.00'],
        ['amount' => -75, 'text' => 'Overføring', 'stored' => '-75.00'],
        ['amount' => -1200, 'text' => 'Betaling', 'stored' => '-1200.00'],
    ];

    foreach ($reserved as $row) {
        fakeSparebank1(transactions: [
            bankTransaction([
                'id' => 'slot-0',
                'nonUniqueId' => 'slot-0',
                'amount' => $row['amount'],
                'description' => $row['text'],
                'cleanedDescription' => $row['text'],
                'typeText' => $row['text'],
                'remoteAccountNumber' => '9999.00.00009',
                'bookingStatus' => 'PENDING',
            ]),
        ]);

        $this->withToken($token)
            ->postJson('/api/sparebank1/sync')
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('pending', 1);

        $snapshot = $user->pendingBankTransactions()->sole();

        expect($user->transactions()->count())->toBe(1)
            ->and($kiwi->refresh()->getAttributes())->toBe($untouched)
            ->and($snapshot->payee)->toBe($row['text'])
            ->and($snapshot->amount)->toBe($row['stored']);
    }

    fakeSparebank1(transactions: array_map(fn (array $row, int $index): array => bankTransaction([
        'id' => 'booked-'.$index,
        'nonUniqueId' => 'booked-row-'.$index,
        'amount' => $row['amount'],
        'description' => $row['text'],
        'cleanedDescription' => $row['text'],
        'typeText' => $row['text'],
        'remoteAccountNumber' => '9999.00.00009',
    ]), $reserved, array_keys($reserved)));

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 3)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('pending', 0);

    expect($user->transactions()->count())->toBe(4)
        ->and($kiwi->refresh()->getAttributes())->toBe($untouched)
        ->and($user->transactions()->where('booking_status', BookingStatus::Booked->value)->count())->toBe(4)
        ->and($user->transactions()->whereKeyNot($kiwi->id)->orderBy('id')->get()->map->only(['external_id', 'payee', 'amount'])->all())->toBe([
            ['external_id' => 'booked-0', 'payee' => 'Straksbetaling', 'amount' => '250.00'],
            ['external_id' => 'booked-1', 'payee' => 'Overføring', 'amount' => '75.00'],
            ['external_id' => 'booked-2', 'payee' => 'Betaling', 'amount' => '1200.00'],
        ])
        ->and($user->pendingBankTransactions()->count())->toBe(0);
});

test('a row an earlier version imported as reserved is left alone when the bank books it', function () {
    $user = connectedUser();
    $account = $user->bankConnection->accounts()->where('external_key', 'use-1')->sole();
    $account->update(['sync_enabled' => true]);

    $bankRow = bankTransaction([
        'id' => 'legacy-id',
        'nonUniqueId' => 'legacy-row',
        'amount' => -410,
        'description' => 'Betaling',
        'cleanedDescription' => 'Betaling',
    ]);

    $legacy = $user->transactions()->create([
        'external_id' => 'legacy-id',
        'dedupe_key' => app(Sparebank1TransactionParser::class)->parse('use-1', $bankRow)->dedupeKey,
        'bank_account_id' => $account->id,
        'booking_status' => BookingStatus::Pending,
        'booked_on' => '2026-11-09',
        'amount' => '95.00',
        'payee' => 'Straksbetaling',
        'category' => Category::Annet,
        'note' => 'Satt for hånd',
        'needs_review' => false,
        'exclude_from_budget' => true,
    ]);

    $kept = $legacy->refresh()->only(['booking_status', 'booked_on', 'amount', 'payee', 'category', 'note', 'needs_review', 'exclude_from_budget']);

    fakeSparebank1(transactions: [$bankRow]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $booked = $user->transactions()->where('external_id', 'legacy-id')->sole();

    expect($user->transactions()->count())->toBe(2)
        ->and($booked->id)->not->toBe($legacy->id)
        ->and($booked->booking_status)->toBe(BookingStatus::Booked)
        ->and($booked->amount)->toBe('410.00')
        ->and($booked->payee)->toBe('Betaling')
        ->and($booked->note)->not->toBe('Satt for hånd')
        ->and($legacy->refresh()->only(array_keys($kept)))->toEqual($kept)
        ->and($legacy->external_id)->toBeNull()
        ->and($legacy->dedupe_key)->toBeNull();

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->transactions()->count())->toBe(2);
});

test('the snapshot stays when the bank could not fetch its recent rows', function () {
    $user = connectedUserWithCard();
    $first = now();

    fakeSparebank1(transactions: [cardPurchase(['bookingStatus' => 'PENDING'])]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-11-11 18:00:00', 'Europe/Oslo'));

    fakeSparebank1(transactions: [], errors: ['RECENT_TRANSACTIONS_FAILED']);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('pending', 1);

    $row = $user->pendingBankTransactions()->sole();

    expect($row->payee)->toBe('Bokhandel Sentrum')
        ->and($row->last_seen_at->equalTo($first))->toBeTrue()
        ->and($user->bankConnection()->first()->last_sync_error)->toBe('Nylige transaksjoner kunne ikke hentes.');
});

test('the snapshot is dropped for an account that stops syncing and when the bank is disconnected', function () {
    $user = connectedUserWithCard();
    $accounts = $user->bankConnection->accounts();
    $accounts->where('external_key', 'use-1')->update(['sync_enabled' => true]);

    fakeSparebank1(transactions: [cardPurchase(['bookingStatus' => 'PENDING'])]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->pendingBankTransactions()->count())->toBe(2);

    $card = $user->bankConnection->accounts()->where('external_key', 'card-1')->sole();
    $card->update(['sync_enabled' => false]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->pendingBankTransactions()->pluck('bank_account_id')->all())->not->toContain($card->id)
        ->and($user->pendingBankTransactions()->count())->toBe(1);

    $this->actingAs($user);

    Livewire::test('pages::settings.bank')->call('disconnect');

    expect($user->pendingBankTransactions()->count())->toBe(0);
});

test('a sync without any enabled account empties the snapshot', function () {
    $user = connectedUserWithCard();

    fakeSparebank1(transactions: [cardPurchase(['bookingStatus' => 'PENDING'])]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    expect($user->pendingBankTransactions()->count())->toBe(1);

    $user->bankConnection->accounts()->update(['sync_enabled' => false]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertFailed();

    expect($user->pendingBankTransactions()->count())->toBe(0);
});

test('a new bank row gets its defaults from the food rules, the bank text and the account', function () {
    $user = connectedUserWithCard();

    fakeSparebank1(transactions: [
        cardPurchase(),
        bankTransaction([
            'id' => 'card-kiwi',
            'nonUniqueId' => 'card-kiwi-row',
            'amount' => -74.9,
            'description' => 'KIWI 123 SENTRUM',
            'cleanedDescription' => 'Kiwi',
            'accountKey' => 'card-1',
            'typeText' => 'Kredittkort',
        ]),
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 2)
        ->assertJsonPath('updated', 0);

    $unknown = $user->transactions()->where('external_id', 'card-first')->sole();
    $grocery = $user->transactions()->where('external_id', 'card-kiwi')->sole();

    expect($unknown->booking_status)->toBe(BookingStatus::Booked)
        ->and($unknown->amount)->toBe('349.00')
        ->and($unknown->booked_on->toDateString())->toBe('2026-11-10')
        ->and($unknown->payee)->toBe('Bokhandel Sentrum')
        ->and($unknown->note)->toBe('Kredittkort')
        ->and($unknown->category)->toBeNull()
        ->and($unknown->subcategory)->toBeNull()
        ->and($unknown->needs_review)->toBeTrue()
        ->and($unknown->exclude_from_budget)->toBeFalse()
        ->and($unknown->payment_method)->toBe(PaymentMethod::Kredittkort);

    expect($grocery->booking_status)->toBe(BookingStatus::Booked)
        ->and($grocery->amount)->toBe('74.90')
        ->and($grocery->payee)->toBe('Kiwi')
        ->and($grocery->note)->toBe("KIWI 123 SENTRUM\nKredittkort")
        ->and($grocery->category)->toBe(Category::Dagligvarer)
        ->and($grocery->subcategory)->toBeNull()
        ->and($grocery->needs_review)->toBeFalse()
        ->and($grocery->exclude_from_budget)->toBeFalse()
        ->and($grocery->payment_method)->toBe(PaymentMethod::Kredittkort);
});

test('a later sync updates the bank fields on a booked row and keeps manual edits', function (array $edits) {
    $user = connectedUserWithCard();
    $token = $user->createToken('test')->plainTextToken;

    fakeSparebank1(transactions: [cardPurchase()]);

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 1);

    $row = $user->transactions()->sole();

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$row->id, $edits)
        ->assertOk()
        ->assertJson($edits);

    $edited = $row->refresh()->only(manualTransactionFields());

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 1);

    expect($row->refresh()->only(manualTransactionFields()))->toBe($edited)
        ->and($row->booking_status)->toBe(BookingStatus::Booked)
        ->and($row->amount)->toBe('349.00');

    fakeSparebank1(transactions: [
        cardPurchase([
            'id' => 'card-rotated',
            'amount' => -352.5,
            'description' => 'BOKHANDEL SENTRUM OSLO',
            'cleanedDescription' => 'Bokhandel Sentrum Oslo',
            'date' => bankMillis('2026-11-11 09:00:00'),
        ]),
    ]);

    $this->withToken($token)
        ->postJson('/api/sparebank1/sync')
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('updated', 1);

    expect($user->transactions()->count())->toBe(1)
        ->and($row->refresh()->only(manualTransactionFields()))->toBe($edited)
        ->and($row->booking_status)->toBe(BookingStatus::Booked)
        ->and($row->amount)->toBe('352.50')
        ->and($row->booked_on->toDateString())->toBe('2026-11-11')
        ->and($row->payee)->toBe('Bokhandel Sentrum Oslo')
        ->and($row->external_id)->toBe('card-rotated');
})->with([
    'excluded and reviewed, still without a category' => [[
        'exclude_from_budget' => true,
        'needs_review' => false,
        'note' => 'Utlegg som blir refundert',
    ]],
    'categorized by hand' => [[
        'category' => 'Dagligvarer',
        'subcategory' => 'Husholdning',
        'payment_method' => 'Wise',
        'note' => 'Vaskemidler',
        'exclude_from_budget' => false,
        'needs_review' => false,
    ]],
]);

test('a later sync does not move a hand-categorized transfer back outside the budget', function () {
    $user = connectedUser();
    $user->bankConnection->accounts()->where('external_key', 'use-1')->update(['sync_enabled' => true]);
    $token = $user->createToken('test')->plainTextToken;
    $transfer = [
        'id' => 'transfer-first',
        'nonUniqueId' => 'transfer-row',
        'amount' => -1500,
        'description' => 'Overføring',
        'cleanedDescription' => 'Overføring',
        'typeText' => 'Overføring',
        'remoteAccountNumber' => '1200.00.00003',
    ];

    fakeSparebank1(transactions: [bankTransaction($transfer)]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $row = $user->transactions()->sole();

    expect($row->exclude_from_budget)->toBeTrue()
        ->and($row->category)->toBeNull()
        ->and($row->needs_review)->toBeFalse();

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$row->id, [
            'category' => 'Bokostnader',
            'exclude_from_budget' => false,
            'note' => 'Husleie betalt fra sparekontoen',
        ])
        ->assertOk();

    fakeSparebank1(transactions: [
        bankTransaction(array_merge($transfer, ['id' => 'transfer-rotated'])),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $row->refresh();

    expect($user->transactions()->count())->toBe(1)
        ->and($row->booking_status)->toBe(BookingStatus::Booked)
        ->and($row->external_id)->toBe('transfer-rotated')
        ->and($row->category)->toBe(Category::Bokostnader)
        ->and($row->exclude_from_budget)->toBeFalse()
        ->and($row->needs_review)->toBeFalse()
        ->and($row->note)->toBe('Husleie betalt fra sparekontoen');
});

test('a later sync only refreshes the bank fields on a split and leaves its lines alone', function () {
    $user = connectedUserWithCard();
    $token = $user->createToken('test')->plainTextToken;

    fakeSparebank1(transactions: [cardPurchase()]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $parent = $user->transactions()->sole();

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$parent->id, ['note' => 'Kvittering ligger i appen'])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Utdanning', 'amount' => '249.00', 'note' => 'lærebok'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Husholdning', 'amount' => '100.00'],
            ],
        ])
        ->assertOk();

    $edited = $parent->refresh()->only(manualTransactionFields());
    $lines = $parent->children()->orderBy('id')->get()->toArray();

    expect($edited['note'])->toBe('Kvittering ligger i appen')
        ->and($edited['needs_review'])->toBeFalse()
        ->and($lines)->toHaveCount(2);

    fakeSparebank1(transactions: [
        cardPurchase(['id' => 'card-rotated']),
    ]);

    $this->artisan('sparebank1:sync', ['--user' => $user->id])->assertSuccessful();

    $parent->refresh();

    expect($parent->booking_status)->toBe(BookingStatus::Booked)
        ->and($parent->external_id)->toBe('card-rotated')
        ->and($parent->amount)->toBe('349.00')
        ->and($parent->only(manualTransactionFields()))->toBe($edited)
        ->and($parent->children()->orderBy('id')->get()->toArray())->toBe($lines)
        ->and($user->transactions()->whereNull('parent_id')->count())->toBe(1)
        ->and($user->transactions()->inbox()->count())->toBe(0);
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

function fakeSparebank1(array $token = [], ?array $transactions = null, array $errors = []): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();

    Http::fake(function (Request $request) use ($token, $transactions, $errors) {
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
                'errors' => $errors,
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cardPurchase(array $overrides = []): array
{
    return bankTransaction(array_merge([
        'id' => 'card-first',
        'nonUniqueId' => 'card-row',
        'amount' => -349,
        'description' => 'Bokhandel Sentrum',
        'cleanedDescription' => 'Bokhandel Sentrum',
        'accountKey' => 'card-1',
        'typeText' => 'Kredittkort',
    ], $overrides));
}

/**
 * @return list<string>
 */
function manualTransactionFields(): array
{
    return ['category', 'subcategory', 'note', 'exclude_from_budget', 'needs_review', 'payment_method'];
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

function connectedUserWithCard(): User
{
    $user = connectedUser();

    $user->bankConnection->accounts()->where('external_key', 'card-1')->update([
        'sync_enabled' => true,
        'payment_method' => PaymentMethod::Kredittkort,
    ]);

    return $user;
}
