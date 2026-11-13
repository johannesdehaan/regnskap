<?php

use App\Actions\BuildFoodBudget;
use App\Actions\BuildOverview;
use App\Actions\RecordSparebank1Transaction;
use App\Enums\BankConnectionStatus;
use App\Enums\BookingStatus;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Enums\Subcategory;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sparebank1\ParsedBankTransaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('legacy categories stay and dagligvarer subcategories map to their parent', function () {
    expect(collect(Category::cases())->map(fn (Category $category): string => $category->value)->all())
        ->toContain('O', 'Boller og brus', 'Dagligvarer', 'Servering')
        ->and(Category::tryFrom('Matvarer'))->toBeNull()
        ->and(Category::tryFrom('Kaffe og melk'))->toBeNull()
        ->and(Category::tryFrom('Husholdning'))->toBeNull()
        ->and(Category::O->countsAsFood())->toBeFalse()
        ->and(Category::Dagligvarer->countsAsFood())->toBeTrue()
        ->and(Category::BollerOgBrus->countsAsFood())->toBeTrue()
        ->and(Category::Servering->countsAsFood())->toBeTrue()
        ->and(Subcategory::Matvarer->category())->toBe(Category::Dagligvarer)
        ->and(Subcategory::BollerOgBrus->category())->toBe(Category::Dagligvarer)
        ->and(Subcategory::KaffeOgMelk->category())->toBe(Category::Dagligvarer)
        ->and(Subcategory::Husholdning->category())->toBe(Category::Dagligvarer)
        ->and(Subcategory::KaffeOgMelk->countsAsFood())->toBeTrue()
        ->and(Subcategory::Husholdning->countsAsFood())->toBeFalse();
});

test('a receipt split creates children that sum to the øre and leaves the bank row in place', function () {
    $user = User::factory()->create();
    $account = receiptSplitAccount($user);
    $parent = Transaction::factory()->for($user)->create([
        'amount' => '123.45',
        'booked_on' => '2026-11-13',
        'payee' => 'Kiwi',
        'category' => Category::Dagligvarer,
        'payment_method' => PaymentMethod::Kredittkort,
        'note' => 'kvittering',
        'needs_review' => true,
        'bank_account_id' => $account->id,
        'external_id' => 'bank-parent',
        'dedupe_key' => hash('sha256', 'bank-parent'),
    ]);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '100.00', 'note' => 'melk'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Kaffe og melk', 'amount' => '13.45'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Husholdning', 'amount' => '10.00', 'note' => 'såpe'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('id', $parent->id)
        ->assertJsonPath('needs_review', false)
        ->assertJsonPath('external_id', 'bank-parent')
        ->assertJsonPath('children.0.category', 'Dagligvarer')
        ->assertJsonPath('children.0.subcategory', 'Matvarer')
        ->assertJsonPath('children.0.amount', '100.00')
        ->assertJsonPath('children.0.note', 'melk')
        ->assertJsonPath('children.0.payee', 'Kiwi')
        ->assertJsonPath('children.0.payment_method', 'Kredittkort')
        ->assertJsonPath('children.0.needs_review', false)
        ->assertJsonPath('children.0.exclude_from_budget', false)
        ->assertJsonPath('children.0.bank_account_id', null)
        ->assertJsonPath('children.0.external_id', null)
        ->assertJsonPath('children.0.dedupe_key', null)
        ->assertJsonPath('children.1.subcategory', 'Kaffe og melk')
        ->assertJsonPath('children.1.note', null)
        ->assertJsonPath('children.2.subcategory', 'Husholdning');

    $parent->refresh();
    $children = $parent->children()->orderBy('id')->get();

    expect($children)->toHaveCount(3)
        ->and($children->sum(fn (Transaction $child): int => (int) round(((float) $child->amount) * 100)))->toBe(12345)
        ->and($children->every(fn (Transaction $child): bool => $child->booked_on->toDateString() === '2026-11-13'))->toBeTrue()
        ->and($children->every(fn (Transaction $child): bool => $child->parent_id === $parent->id))->toBeTrue()
        ->and($user->transactions()->inbox()->whereKey($parent->id)->exists())->toBeFalse()
        ->and($response->json('children.0.id'))->not->toBe($parent->id);
});

test('a split whose lines do not sum to the parent is rejected and keeps existing children', function () {
    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create([
        'amount' => '100.00',
        'payee' => 'Kiwi',
        'booked_on' => '2026-11-13',
    ]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '40.00'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Husholdning', 'amount' => '50.00'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');

    expect($parent->children()->count())->toBe(0);

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '60.00', 'note' => 'beholdes'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Kaffe og melk', 'amount' => '40.00'],
            ],
        ])
        ->assertOk();

    $kept = $parent->children()->pluck('id');

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '10.00'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '10.09'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');

    expect($parent->children()->pluck('id')->all())->toBe($kept->all())
        ->and($parent->children()->where('note', 'beholdes')->exists())->toBeTrue();
});

test('a subcategory must belong to the line category', function () {
    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create([
        'amount' => '20.00',
        'booked_on' => '2026-11-13',
    ]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Bil', 'subcategory' => 'Husholdning', 'amount' => '20.00'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.subcategory');

    expect($parent->children()->count())->toBe(0);

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-11-13',
            'amount' => '15.00',
            'payee' => 'Biltema',
            'category' => 'Bil',
            'subcategory' => 'Kaffe og melk',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subcategory');
});

test('only the owner can split a transaction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = Transaction::factory()->for($other)->create([
        'amount' => '30.00',
        'booked_on' => '2026-11-13',
    ]);
    $token = $user->createToken('test')->plainTextToken;
    $payload = [
        'lines' => [
            ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '30.00'],
        ],
    ];

    $this->postJson('/api/transactions/'.$theirs->id.'/split', $payload)->assertUnauthorized();
    $this->deleteJson('/api/transactions/'.$theirs->id.'/split')->assertUnauthorized();

    $this->withToken($token)
        ->postJson('/api/transactions/'.$theirs->id.'/split', $payload)
        ->assertForbidden();

    $this->withToken($token)
        ->deleteJson('/api/transactions/'.$theirs->id.'/split')
        ->assertForbidden();

    $this->withToken($token)
        ->postJson('/api/transactions/999999/split', $payload)
        ->assertNotFound();

    expect($theirs->children()->count())->toBe(0);
});

test('splitting again replaces the previous lines and delete removes them', function () {
    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create([
        'amount' => '80.00',
        'payee' => 'Meny',
        'booked_on' => '2026-11-13',
        'needs_review' => true,
    ]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '50.00'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Boller og brus', 'amount' => '30.00'],
            ],
        ])
        ->assertOk();

    $firstIds = $parent->children()->pluck('id');

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Kaffe og melk', 'amount' => '80.00', 'note' => 'bønner'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('children.0.subcategory', 'Kaffe og melk')
        ->assertJsonPath('children.0.note', 'bønner')
        ->assertJsonCount(1, 'children');

    expect($parent->children()->count())->toBe(1)
        ->and(Transaction::query()->whereIn('id', $firstIds)->exists())->toBeFalse()
        ->and($parent->fresh()->needs_review)->toBeFalse();

    $childId = $parent->children()->value('id');

    $this->withToken($token)
        ->postJson('/api/transactions/'.$childId.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '80.00'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('transaction');

    $this->withToken($token)
        ->deleteJson('/api/transactions/'.$parent->id.'/split')
        ->assertOk()
        ->assertJsonPath('id', $parent->id)
        ->assertJsonCount(0, 'children');

    expect($parent->fresh()->exists)->toBeTrue()
        ->and($parent->children()->count())->toBe(0)
        ->and(Transaction::query()->whereKey($childId)->exists())->toBeFalse();
});

test('more than twenty lines is rejected', function () {
    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create([
        'amount' => '21.00',
        'booked_on' => '2026-11-13',
    ]);
    $lines = [];

    for ($i = 0; $i < 21; $i++) {
        $lines[] = ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '1.00'];
    }

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/transactions/'.$parent->id.'/split', ['lines' => $lines])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');

    expect($parent->children()->count())->toBe(0);
});

test('food budget and monthly overview count split children and treat husholdning as not food', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $parent = Transaction::factory()->for($user)->create([
        'payee' => 'Kiwi',
        'amount' => '100.00',
        'booked_on' => '2026-11-13',
        'category' => Category::Dagligvarer,
        'needs_review' => false,
    ]);

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '40.00'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Husholdning', 'amount' => '60.00'],
            ],
        ])
        ->assertOk();

    Transaction::factory()->for($user)->create([
        'payee' => 'Kaffebrenneriet',
        'amount' => '15.00',
        'booked_on' => '2026-11-13',
        'category' => Category::Dagligvarer,
        'subcategory' => Subcategory::KaffeOgMelk,
        'needs_review' => false,
    ]);
    Transaction::factory()->for($user)->create([
        'payee' => 'Nille',
        'amount' => '25.00',
        'booked_on' => '2026-11-13',
        'category' => Category::Dagligvarer,
        'subcategory' => Subcategory::Husholdning,
        'needs_review' => false,
    ]);
    Transaction::factory()->for($user)->create([
        'payee' => 'Bakeriet',
        'amount' => '5.00',
        'booked_on' => '2026-11-13',
        'category' => Category::BollerOgBrus,
        'needs_review' => false,
    ]);

    $status = app(BuildFoodBudget::class)($user);

    expect($status['spent'])->toBe('60.00');

    $this->withToken($token)
        ->getJson('/api/food-budget')
        ->assertOk()
        ->assertJsonPath('spent', '60.00');

    $bilParent = Transaction::factory()->for($user)->create([
        'payee' => 'Coop Extra',
        'amount' => '100.00',
        'booked_on' => '2026-11-13',
        'category' => Category::Dagligvarer,
    ]);
    $bilParent->children()->create([
        'user_id' => $user->id,
        'booked_on' => '2026-11-13',
        'amount' => '70.00',
        'category' => Category::Bil,
        'payee' => 'Coop Extra',
        'needs_review' => false,
        'exclude_from_budget' => false,
    ]);
    $bilParent->children()->create([
        'user_id' => $user->id,
        'booked_on' => '2026-11-13',
        'amount' => '30.00',
        'category' => Category::Dagligvarer,
        'subcategory' => Subcategory::KaffeOgMelk,
        'payee' => 'Coop Extra',
        'needs_review' => false,
        'exclude_from_budget' => false,
    ]);

    $overview = app(BuildOverview::class)($user, '2026-11');
    $rows = collect($overview['rows']);

    expect($overview['spent'])->toBe(245.0)
        ->and($rows->firstWhere('name', 'Dagligvarer')['spent'])->toBe(170.0)
        ->and($rows->firstWhere('name', 'Boller og brus')['spent'])->toBe(5.0)
        ->and($rows->firstWhere('name', 'Bil')['spent'])->toBe(70.0);

    $this->withToken($token)
        ->getJson('/api/overview?month=2026-11')
        ->assertOk()
        ->assertJsonPath('spent', 245);
});

test('the transaction list shows subcategories and does not double count a split parent', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create([
        'payee' => 'Obs',
        'amount' => '100.00',
        'booked_on' => '2026-11-13',
        'category' => Category::Dagligvarer,
        'note' => 'foreldrenotat',
    ]);
    $parent->children()->create([
        'user_id' => $user->id,
        'booked_on' => '2026-11-13',
        'amount' => '70.00',
        'category' => Category::Dagligvarer,
        'subcategory' => Subcategory::KaffeOgMelk,
        'payee' => 'Obs',
        'note' => 'kaffe',
    ]);
    $parent->children()->create([
        'user_id' => $user->id,
        'booked_on' => '2026-11-13',
        'amount' => '30.00',
        'category' => Category::Dagligvarer,
        'subcategory' => Subcategory::Husholdning,
        'payee' => 'Obs',
        'note' => 'papir',
    ]);

    $token = $user->createToken('test')->plainTextToken;
    $listed = $this->withToken($token)
        ->getJson('/api/transactions?month=2026-11')
        ->assertOk()
        ->assertJsonFragment(['subcategory' => 'Kaffe og melk'])
        ->assertJsonFragment(['subcategory' => 'Husholdning'])
        ->assertJsonMissing(['id' => $parent->id]);

    $cents = collect($listed->json())->sum(fn (array $row): int => (int) round(((float) $row['amount']) * 100));

    expect($cents)->toBe(10000);

    $this->actingAs($user);

    Livewire::test('pages::transactions.index')
        ->set('month', '2026-11')
        ->assertSee('Kaffe og melk')
        ->assertSee('Husholdning')
        ->assertSee('70,00')
        ->assertSee('30,00')
        ->assertDontSee('100,00')
        ->assertDontSee('foreldrenotat');
});

test('transactions can be stored and updated with a subcategory', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $created = $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-11-13',
            'amount' => '42.50',
            'payee' => 'Kaffebrenneriet',
            'category' => 'Dagligvarer',
            'subcategory' => 'Kaffe og melk',
        ])
        ->assertCreated()
        ->assertJsonPath('category', 'Dagligvarer')
        ->assertJsonPath('subcategory', 'Kaffe og melk');

    $id = $created->json('id');

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$id, ['category' => 'Bil'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subcategory');

    expect(Transaction::query()->find($id)->subcategory)->toBe(Subcategory::KaffeOgMelk);

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$id, [
            'category' => 'Bil',
            'subcategory' => null,
        ])
        ->assertOk()
        ->assertJsonPath('category', 'Bil')
        ->assertJsonPath('subcategory', null);

    $this->actingAs($user);

    Livewire::test('pages::transactions.create')
        ->set('payee', 'Kiwi')
        ->set('amount', '18.00')
        ->set('booked_on', '2026-11-13')
        ->set('category', Category::Dagligvarer->value)
        ->set('subcategory', Subcategory::Matvarer->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->transactions()->where('payee', 'Kiwi')->first()->subcategory)->toBe(Subcategory::Matvarer);

    Livewire::test('pages::transactions.create')
        ->set('payee', 'Feil')
        ->set('amount', '9.00')
        ->set('booked_on', '2026-11-13')
        ->set('category', Category::Bil->value)
        ->set('subcategory', Subcategory::Husholdning->value)
        ->call('save')
        ->assertHasErrors('subcategory');
});

test('a sparebank sync that changes the amount flags the split parent and keeps the children', function () {
    $user = User::factory()->create();
    $account = receiptSplitAccount($user);
    $record = app(RecordSparebank1Transaction::class);

    expect($record($user, $account, receiptSplitParsed('pending-id', '80.00', BookingStatus::Pending), []))->toBe('imported');

    $parent = $user->transactions()->whereNull('parent_id')->first();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions/'.$parent->id.'/split', [
            'lines' => [
                ['category' => 'Dagligvarer', 'subcategory' => 'Matvarer', 'amount' => '50.00'],
                ['category' => 'Dagligvarer', 'subcategory' => 'Husholdning', 'amount' => '30.00'],
            ],
        ])
        ->assertOk();

    $childIds = $parent->children()->pluck('id');

    expect($record($user, $account, receiptSplitParsed('booked-same', '80.00', BookingStatus::Booked), []))->toBe('updated');

    $parent->refresh();

    expect($parent->booking_status)->toBe(BookingStatus::Booked)
        ->and($parent->needs_review)->toBeFalse()
        ->and($parent->amount)->toBe('80.00')
        ->and($parent->children()->pluck('id')->all())->toBe($childIds->all())
        ->and($user->transactions()->whereNull('parent_id')->count())->toBe(1);

    expect($record($user, $account, receiptSplitParsed('booked-new-amount', '95.00', BookingStatus::Booked), []))->toBe('updated');

    $parent->refresh();

    expect($record($user, $account, receiptSplitParsed('booked-new-amount', '110.00', BookingStatus::Booked), []))->toBe('updated');

    $parent->refresh();

    expect($parent->amount)->toBe('110.00')
        ->and($parent->needs_review)->toBeTrue()
        ->and($parent->external_id)->toBe('booked-new-amount')
        ->and($parent->children()->pluck('id')->all())->toBe($childIds->all())
        ->and($parent->children()->whereNotNull('external_id')->exists())->toBeFalse()
        ->and($parent->children()->whereNotNull('dedupe_key')->exists())->toBeFalse()
        ->and($parent->children()->whereNotNull('bank_account_id')->exists())->toBeFalse()
        ->and($user->transactions()->whereNull('parent_id')->count())->toBe(1);

    $this->withToken($token)
        ->getJson('/api/transactions?inbox=1')
        ->assertOk()
        ->assertJsonFragment(['id' => $parent->id, 'needs_review' => true]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->assertSee('Rema 1000')
        ->assertSee('Beløpet er endret etter deling')
        ->set('splitAmount', '40.00')
        ->set('splitCategoryA', Category::Dagligvarer->value)
        ->set('splitCategoryB', Category::Bil->value)
        ->call('split')
        ->assertHasNoErrors();

    $parent->refresh();

    expect($parent->needs_review)->toBeFalse()
        ->and($parent->children)->toHaveCount(2)
        ->and(number_format((float) $parent->children->sum('amount'), 2, '.', ''))->toBe('110.00')
        ->and($parent->children->pluck('category')->all())->toEqual([
            Category::Dagligvarer,
            Category::Bil,
        ])
        ->and($user->transactions()->inbox()->count())->toBe(0)
        ->and(Transaction::query()->whereIn('id', $childIds)->exists())->toBeFalse();
});

function receiptSplitAccount(User $user): BankAccount
{
    $connection = BankConnection::query()->create([
        'user_id' => $user->id,
        'status' => BankConnectionStatus::Connected,
    ]);

    return BankAccount::query()->create([
        'bank_connection_id' => $connection->id,
        'user_id' => $user->id,
        'external_key' => 'acc-'.$user->id,
        'name' => 'Brukskonto',
        'account_number' => '12345678901',
        'account_type' => 'Brukskonto',
        'currency_code' => 'NOK',
        'payment_method' => PaymentMethod::Brukskonto,
        'sync_enabled' => true,
    ]);
}

function receiptSplitParsed(string $externalId, string $amount, BookingStatus $status): ParsedBankTransaction
{
    $dedupe = hash('sha256', 'stable-rema');

    return new ParsedBankTransaction(
        externalId: $externalId,
        dedupeKey: $dedupe,
        lookupKeys: [$dedupe],
        bookedOn: CarbonImmutable::parse('2026-11-13', 'Europe/Oslo'),
        absoluteAmount: $amount,
        payee: 'Rema 1000',
        note: null,
        bookingStatus: $status,
        remoteAccountNumber: null,
        typeText: null,
    );
}
