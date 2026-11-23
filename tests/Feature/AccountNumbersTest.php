<?php

use App\Enums\BankConnectionStatus;
use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountNumbers;
use Livewire\Livewire;

test('the transaction list shows the account number next to the name of a synced account', function () {
    $user = User::factory()->create();
    $smn = numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);

    numberedTransaction($user, 'Synket butikk', PaymentMethod::BrukskontoSmn, ['bank_account_id' => $smn->id]);
    numberedTransaction($user, 'Manuell butikk', PaymentMethod::BrukskontoHegra);
    numberedTransaction($user, 'Kortbutikk', PaymentMethod::Kredittkort);

    $this->actingAs($user);

    $list = numberedPageText(Livewire::test('pages::transactions.index')->set('month', '2026-08')->html());

    expect($list)->toContain('Synket butikk 100,00 08.08.2026 · Dagligvarer · Brukskonto SMN · 1200.00.00011')
        ->toContain('Manuell butikk 100,00 08.08.2026 · Dagligvarer · Brukskonto Hegra')
        ->toContain('Kortbutikk 100,00 08.08.2026 · Dagligvarer · Kredittkort')
        ->not->toContain('Brukskonto Hegra ·')
        ->not->toContain('Kredittkort ·');

    Livewire::test('pages::transactions.index')
        ->set('month', '2026-08')
        ->set('payment_method', PaymentMethod::BrukskontoSmn->value)
        ->assertSee('Synket butikk')
        ->assertDontSee('Manuell butikk');

    Livewire::test('pages::transactions.index')
        ->set('month', '2026-08')
        ->set('payment_method', PaymentMethod::BrukskontoHegra->value)
        ->assertSee('Manuell butikk')
        ->assertDontSee('Synket butikk');
});

test('the payment method filter and the new transaction form list the number with the name', function () {
    $user = User::factory()->create();
    numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);

    $this->actingAs($user);

    Livewire::test('pages::transactions.index')
        ->assertSee('Brukskonto SMN · 1200.00.00011')
        ->assertSee('Brukskonto Hegra')
        ->assertDontSee('Brukskonto Hegra ·');

    Livewire::test('pages::transactions.create')
        ->assertSee('Brukskonto SMN · 1200.00.00011')
        ->assertDontSee('Brukskonto Hegra ·')
        ->set('amount', '10')
        ->set('payee', 'Butikk')
        ->set('category', Category::Annet->value)
        ->set('payment_method', PaymentMethod::BrukskontoSmn->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->transactions()->first()->payment_method)->toBe(PaymentMethod::BrukskontoSmn);
});

test('split lines show the number of the account their bank row came from', function () {
    $user = User::factory()->create();
    $first = numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);
    $second = numberedBankAccount($user, 'smn-2', '12000000022', PaymentMethod::BrukskontoSmn);

    $bankRow = numberedTransaction($user, 'Delt kjøp', PaymentMethod::BrukskontoSmn, ['bank_account_id' => $second->id]);
    numberedTransaction($user, 'Delt kjøp', PaymentMethod::BrukskontoSmn, ['parent_id' => $bankRow->id]);
    numberedTransaction($user, 'Første konto', PaymentMethod::BrukskontoSmn, ['bank_account_id' => $first->id]);
    numberedTransaction($user, 'For hånd', PaymentMethod::BrukskontoSmn);

    $this->actingAs($user);

    $list = numberedPageText(Livewire::test('pages::transactions.index')->set('month', '2026-08')->html());

    expect($list)->toContain('Delt kjøp 100,00 08.08.2026 · Dagligvarer · Brukskonto SMN · 1200.00.00022')
        ->toContain('Første konto 100,00 08.08.2026 · Dagligvarer · Brukskonto SMN · 1200.00.00011')
        ->toContain('For hånd 100,00 08.08.2026 · Dagligvarer · Brukskonto SMN Utenfor');

    $numbers = new AccountNumbers($user);

    expect($numbers->forName('Brukskonto SMN'))->toBeNull()
        ->and($numbers->label(PaymentMethod::BrukskontoSmn))->toBe('Brukskonto SMN');
});

test('the inbox shows the account number of a synced row', function () {
    $user = User::factory()->create();
    $smn = numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);

    numberedTransaction($user, 'Uavklart butikk', PaymentMethod::BrukskontoSmn, [
        'bank_account_id' => $smn->id,
        'category' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertSee('Uavklart butikk')
        ->assertSee('Brukskonto SMN · 1200.00.00011');
});

test('the bank settings show each account as name and number, and which number a payment method is mapped to', function () {
    $user = User::factory()->create();
    numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);
    numberedBankAccount($user, 'smn-2', '12000000022', null, 'Lønnskonto');

    $this->actingAs($user);

    Livewire::test('pages::settings.bank')
        ->assertSee('Brukskonto · 1200.00.00011')
        ->assertSee('Lønnskonto · 1200.00.00022')
        ->assertSee('Brukskonto SMN · 1200.00.00011')
        ->assertDontSee('Brukskonto Hegra ·')
        ->set('accountRows.1.payment_method', PaymentMethod::LonnskontoSparebank1->value)
        ->call('saveAccounts')
        ->assertHasNoErrors()
        ->assertSee('Lønnskonto Sparebank 1 · 1200.00.00022');
});

test('the account list shows the number of the synced account with the same name', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);
    numberedBankAccount($other, 'smn-1', '12000000099', PaymentMethod::BrukskontoHegra);

    Account::factory()->for($user)->create(['name' => 'Brukskonto SMN', 'type' => 'brukskonto']);
    Account::factory()->for($user)->create(['name' => 'Brukskonto Hegra', 'type' => 'brukskonto']);
    Account::factory()->for($user)->create(['name' => 'Aksjesparekonto', 'type' => 'investering']);

    $this->actingAs($user);

    Livewire::test('pages::accounts.index')
        ->assertSee('Brukskonto SMN · 1200.00.00011')
        ->assertSee('Brukskonto Hegra')
        ->assertSee('Aksjesparekonto')
        ->assertDontSee('Brukskonto Hegra ·')
        ->assertDontSee('Aksjesparekonto ·')
        ->assertDontSee('1200.00.00099');

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/accounts')
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonPath('0.name', 'Aksjesparekonto')
        ->assertJsonPath('0.account_display_number', null)
        ->assertJsonPath('1.name', 'Brukskonto Hegra')
        ->assertJsonPath('1.type', 'brukskonto')
        ->assertJsonPath('1.account_display_number', null)
        ->assertJsonPath('2.name', 'Brukskonto SMN')
        ->assertJsonPath('2.account_display_number', '1200.00.00011');
});

test('creating and renaming an account through the api reports its number', function () {
    $user = User::factory()->create();
    numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);
    $token = $user->createToken('test')->plainTextToken;

    $id = $this->withToken($token)
        ->postJson('/api/accounts', ['name' => 'Brukskonto', 'type' => 'brukskonto'])
        ->assertCreated()
        ->assertJsonPath('name', 'Brukskonto')
        ->assertJsonPath('type', 'brukskonto')
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('account_display_number', null)
        ->json('id');

    $this->withToken($token)
        ->patchJson('/api/accounts/'.$id, ['name' => 'Brukskonto SMN'])
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('name', 'Brukskonto SMN')
        ->assertJsonPath('account_display_number', '1200.00.00011');
});

test('transaction payloads keep their fields and add the account number', function () {
    $user = User::factory()->create();
    $smn = numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);
    $unmapped = numberedBankAccount($user, 'smn-2', '12000000022', null);
    $token = $user->createToken('test')->plainTextToken;

    $bankRow = numberedTransaction($user, 'Synket', PaymentMethod::BrukskontoSmn, [
        'bank_account_id' => $smn->id,
        'booking_status' => BookingStatus::Booked,
        'external_id' => 'bank-1',
        'booked_on' => '2026-08-04',
    ]);
    numberedTransaction($user, 'Uten betalingsmåte', null, ['bank_account_id' => $unmapped->id, 'booked_on' => '2026-08-03']);
    numberedTransaction($user, 'For hånd SMN', PaymentMethod::BrukskontoSmn, ['booked_on' => '2026-08-02']);
    numberedTransaction($user, 'For hånd Hegra', PaymentMethod::BrukskontoHegra, ['booked_on' => '2026-08-01']);

    $rows = $this->withToken($token)
        ->getJson('/api/transactions')
        ->assertOk()
        ->assertJsonCount(4)
        ->assertJsonPath('0.payee', 'Synket')
        ->assertJsonPath('0.payment_method', 'Brukskonto SMN')
        ->assertJsonPath('0.bank_account_id', $smn->id)
        ->assertJsonPath('0.account_display_number', '1200.00.00011')
        ->assertJsonPath('1.payment_method', null)
        ->assertJsonPath('1.account_display_number', '1200.00.00022')
        ->assertJsonPath('2.payment_method', 'Brukskonto SMN')
        ->assertJsonPath('2.account_display_number', '1200.00.00011')
        ->assertJsonPath('3.payment_method', 'Brukskonto Hegra')
        ->assertJsonPath('3.account_display_number', null)
        ->json();

    expect(array_keys($rows[0]))->toEqualCanonicalizing([
        ...array_keys($bankRow->fresh()->toArray()),
        'account_display_number',
    ])->and(collect($rows[0])->except('account_display_number')->all())->toEqual($bankRow->fresh()->toArray());

    $this->withToken($token)
        ->postJson('/api/transactions/'.$bankRow->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'amount' => '60.00'],
                ['category' => 'Annet', 'amount' => '40.00'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('id', $bankRow->id)
        ->assertJsonPath('account_display_number', '1200.00.00011')
        ->assertJsonCount(2, 'children')
        ->assertJsonPath('children.0.payment_method', 'Brukskonto SMN')
        ->assertJsonPath('children.0.bank_account_id', null)
        ->assertJsonPath('children.0.account_display_number', '1200.00.00011')
        ->assertJsonPath('children.1.account_display_number', '1200.00.00011')
        ->assertJsonMissingPath('children.0.parent');

    $line = $bankRow->children()->orderBy('id')->first();

    $this->withToken($token)
        ->getJson('/api/transactions?q=Synket')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.parent_id', $bankRow->id)
        ->assertJsonPath('0.account_display_number', '1200.00.00011')
        ->assertJsonMissingPath('0.parent');

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$line->id, ['note' => 'melk'])
        ->assertOk()
        ->assertJsonPath('note', 'melk')
        ->assertJsonPath('account_display_number', '1200.00.00011')
        ->assertJsonMissingPath('parent');

    $this->withToken($token)
        ->deleteJson('/api/transactions/'.$bankRow->id.'/split')
        ->assertOk()
        ->assertJsonPath('account_display_number', '1200.00.00011')
        ->assertJsonCount(0, 'children');

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-08-15',
            'amount' => '10.00',
            'payee' => 'Ny',
            'category' => 'Annet',
            'payment_method' => 'Brukskonto SMN',
        ])
        ->assertCreated()
        ->assertJsonPath('payment_method', 'Brukskonto SMN')
        ->assertJsonPath('account_display_number', '1200.00.00011');
});

test('a row keeps the number of the account it came from when its payment method is changed by hand', function () {
    $user = User::factory()->create();
    $smn = numberedBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn);
    $numbers = new AccountNumbers($user);

    $relabelled = numberedTransaction($user, 'Synket', PaymentMethod::Kredittkort, ['bank_account_id' => $smn->id]);
    $byHand = numberedTransaction($user, 'For hånd', PaymentMethod::Kredittkort);

    expect($numbers->transactionLabel($relabelled))->toBe('Kredittkort · 1200.00.00011')
        ->and($numbers->transactionLabel($byHand))->toBe('Kredittkort')
        ->and($numbers->transactionLabel(numberedTransaction($user, 'Uten', null)))->toBeNull();
});

/**
 * What the page says, without markup and with every run of whitespace as one space.
 */
function numberedPageText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))));
}

function numberedBankAccount(User $user, string $key, string $number, ?PaymentMethod $method, string $name = 'Brukskonto'): BankAccount
{
    $connection = BankConnection::query()->firstOrCreate(
        ['user_id' => $user->id],
        ['status' => BankConnectionStatus::Connected],
    );

    return BankAccount::query()->create([
        'bank_connection_id' => $connection->id,
        'user_id' => $user->id,
        'external_key' => $key,
        'name' => $name,
        'account_number' => $number,
        'account_type' => 'deposit',
        'currency_code' => 'NOK',
        'payment_method' => $method,
        'sync_enabled' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function numberedTransaction(User $user, string $payee, ?PaymentMethod $method, array $attributes = []): Transaction
{
    return Transaction::factory()->for($user)->create(array_merge([
        'payee' => $payee,
        'amount' => '100.00',
        'category' => Category::Dagligvarer,
        'payment_method' => $method,
        'note' => null,
        'booked_on' => '2026-08-08',
    ], $attributes));
}
