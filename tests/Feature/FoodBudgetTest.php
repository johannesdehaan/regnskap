<?php

use App\Actions\BuildFoodBudget;
use App\Actions\ClassifyFoodPurchase;
use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function foodPurchase(User $user, string $payee, string $amount, string $date, ?string $note = null): Transaction
{
    $classification = app(ClassifyFoodPurchase::class)($user, $payee, $note);

    return Transaction::factory()->for($user)->create([
        'payee' => $payee,
        'note' => $note,
        'amount' => $amount,
        'booked_on' => $date,
        'category' => $classification->category,
        'needs_review' => $classification->needsReview,
        'exclude_from_budget' => false,
        'payment_method' => null,
    ]);
}

test('the rolling window keeps six earlier days and drops the day before that', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();

    foodPurchase($user, 'Kiwi', '80.00', '2026-03-03');
    foodPurchase($user, 'Rema', '200.00', '2026-03-04');
    foodPurchase($user, 'Pizzabakeren', '50.00', '2026-03-10');
    Transaction::factory()->for($user)->create([
        'payee' => 'H&M',
        'amount' => '900.00',
        'booked_on' => '2026-03-10',
        'category' => Category::Klaer,
        'needs_review' => false,
    ]);

    $status = app(BuildFoodBudget::class)($user);

    expect($status['today'])->toBe('2026-03-10')
        ->and($status['window_days'])->toBe(7)
        ->and($status['amount'])->toBe('1400.00')
        ->and($status['spent'])->toBe('250.00')
        ->and($status['remaining_today'])->toBe('1150.00')
        ->and($status['over_limit'])->toBeFalse()
        ->and($status['days'][0]['date'])->toBe('2026-03-04')
        ->and($status['days'][0]['spent'])->toBe('200.00')
        ->and($status['days'][6]['spent'])->toBe('50.00')
        ->and($status['rolloff'][0]['date'])->toBe('2026-03-11')
        ->and($status['rolloff'][0]['drops'])->toBe('2026-03-04')
        ->and($status['rolloff'][0]['frees'])->toBe('200.00')
        ->and($status['rolloff'][0]['remaining_if_no_new_spending'])->toBe('1350.00')
        ->and($status['rolloff'][6]['drops'])->toBe('2026-03-10')
        ->and($status['rolloff'][6]['frees'])->toBe('50.00');
});

test('days are counted in Europe/Oslo around midnight utc', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 23:30:00', 'UTC'));

    $user = User::factory()->create();

    foodPurchase($user, 'Kiwi', '10.00', '2026-01-09');
    foodPurchase($user, 'Kiwi', '20.00', '2026-01-10');
    foodPurchase($user, 'Kiwi', '30.00', '2026-01-16');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['today'])->toBe('2026-01-16')
        ->and($status['timezone'])->toBe('Europe/Oslo')
        ->and($status['spent'])->toBe('50.00')
        ->and($status['remaining_today'])->toBe('1350.00');
});

test('a day over the limit leaves a negative remainder', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 08:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    foodPurchase($user, 'Rema 1000', '1600.00', '2026-04-02');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['spent'])->toBe('1600.00')
        ->and($status['remaining_today'])->toBe('-200.00')
        ->and($status['over_limit'])->toBeTrue();
});

test('own account transfers, credit card payments and income do not count', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-01 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $date = '2026-05-01';

    foreach ([
        'Overføring til Sparekonto Handelsbanken',
        'Innbetaling kredittkort',
        'Lønn Acme',
    ] as $payee) {
        Transaction::factory()->for($user)->create([
            'payee' => $payee,
            'amount' => '400.00',
            'booked_on' => $date,
            'category' => Category::Dagligvarer,
            'needs_review' => false,
        ]);
    }

    foodPurchase($user, 'Vipps', '75.00', $date, 'Rema Digernes');
    foodPurchase($user, 'Vipps', '40.00', $date, 'mat til Ola');
    foodPurchase($user, 'Overføring', '25.00', $date, 'dagligvarer');
    $plain = foodPurchase($user, 'Vipps', '15.00', $date);

    $status = app(BuildFoodBudget::class)($user);

    expect($plain->needs_review)->toBeTrue()
        ->and($plain->category)->toBeNull()
        ->and($status['spent'])->toBe('140.00')
        ->and($status['pending_review'])->toBe('15.00')
        ->and($status['remaining_today'])->toBe('1260.00');
});

test('only food counts, and unclear purchases stay pending', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $date = '2026-06-01';

    foodPurchase($user, 'REMA 1000 DIGERNES', '100.00', $date);
    foodPurchase($user, 'KIWI 512', '20.00', $date);
    foodPurchase($user, 'EXTRA DIGERNES', '10.00', $date);
    foodPurchase($user, 'Coop Extra Lagune', '11.00', $date);
    foodPurchase($user, 'Obs City Lade Trondheim', '30.00', $date);
    foodPurchase($user, 'Coop Obs Lagune', '12.00', $date);
    foodPurchase($user, 'Obs Hypermarked', '13.00', $date);
    foodPurchase($user, 'Narvesen', '8.00', $date);
    foodPurchase($user, 'Sushi Bar', '9.00', $date);

    foodPurchase($user, 'Circle K', '40.00', $date);
    foodPurchase($user, 'Esso', '900.00', $date);
    foodPurchase($user, 'Circle K Burger', '60.00', $date);
    foodPurchase($user, 'Europris', '70.00', $date);
    foodPurchase($user, 'Ukjent sted', '5.00', $date);

    foodPurchase($user, 'Obs Bygg', '500.00', $date);
    foodPurchase($user, 'Coop Byggmix', '500.00', $date);
    foodPurchase($user, 'Coop Elektro', '500.00', $date);
    foodPurchase($user, 'Vinmonopolet Test', '500.00', $date);
    foodPurchase($user, 'Express Malvik Svebergvegen', '700.00', $date);
    foodPurchase($user, 'Express Esso Malvik', '80.00', $date);
    Transaction::factory()->for($user)->create([
        'payee' => 'H&M',
        'amount' => '300.00',
        'booked_on' => $date,
        'category' => Category::Klaer,
        'needs_review' => false,
    ]);

    Transaction::factory()->for($user)->create([
        'payee' => 'Kiwi',
        'amount' => '33.00',
        'booked_on' => $date,
        'category' => Category::Dagligvarer,
        'needs_review' => false,
        'exclude_from_budget' => true,
    ]);

    $status = app(BuildFoodBudget::class)($user);

    expect($status['spent'])->toBe('213.00')
        ->and($status['pending_review'])->toBe('1075.00');

    expect(foodPurchase($user, 'Shell Express', '4.00', $date)->needs_review)->toBeTrue();
});

test('express fuel is categorized as car and beats the gas station rule', function () {
    $user = User::factory()->create();

    $express = app(ClassifyFoodPurchase::class)($user, 'Express Malvik Svebergvegen');
    $expressEsso = app(ClassifyFoodPurchase::class)($user, 'Express Esso Malvik');
    $shell = app(ClassifyFoodPurchase::class)($user, 'Shell Express');
    $obsBygg = app(ClassifyFoodPurchase::class)($user, 'Obs Bygg Lade');
    $obs = app(ClassifyFoodPurchase::class)($user, 'Obs City Lade Trondheim');

    expect($express->category)->toBe(Category::Bil)
        ->and($express->needsReview)->toBeFalse()
        ->and($expressEsso->category)->toBe(Category::Bil)
        ->and($expressEsso->needsReview)->toBeFalse()
        ->and($shell->category)->toBeNull()
        ->and($shell->needsReview)->toBeTrue()
        ->and($obsBygg->category)->toBeNull()
        ->and($obsBygg->needsReview)->toBeFalse()
        ->and($obs->category)->toBe(Category::Dagligvarer)
        ->and($obs->needsReview)->toBeFalse();
});

test('a split parent is not counted twice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $parent = Transaction::factory()->for($user)->create([
        'payee' => 'Kiwi',
        'amount' => '100.00',
        'booked_on' => '2026-07-01',
        'category' => Category::Dagligvarer,
        'needs_review' => false,
    ]);

    $parent->children()->create([
        'user_id' => $user->id,
        'booked_on' => '2026-07-01',
        'amount' => '100.00',
        'category' => Category::Dagligvarer,
        'payee' => 'Kiwi',
        'needs_review' => false,
        'exclude_from_budget' => false,
    ]);

    $status = app(BuildFoodBudget::class)($user);

    expect($status['spent'])->toBe('100.00');
});

test('guests cannot open the food budget', function () {
    $this->get(route('mat.index'))->assertRedirect(route('login'));
    $this->getJson('/api/food-budget')->assertUnauthorized();
    $this->getJson('/api/food-rules')->assertUnauthorized();
});

test('the food budget page shows the remainder and a pending amount', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    foodPurchase($user, 'Kiwi', '100.00', '2026-08-01');
    foodPurchase($user, 'Circle K', '40.00', '2026-08-01');

    $this->actingAs($user);

    Livewire::test('pages::mat.index')
        ->assertSee('Matbudsjett')
        ->assertSee('igjen å bruke i dag')
        ->assertSee('Under grensen')
        ->assertSee('1 300')
        ->assertSee('Venter på vurdering')
        ->assertSee('40 kr');
});

test('the food budget page shows when the limit is passed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-02 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    foodPurchase($user, 'Rema', '1500.00', '2026-08-02');

    $this->actingAs($user);

    Livewire::test('pages::mat.index')
        ->assertSee('Over grensen');
});

test('a user can change the budget amount and window', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::mat.index')
        ->set('amount', '700')
        ->set('windowDays', 3)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($user->fresh()->food_budget_amount)->toBe('700.00')
        ->and($user->fresh()->food_budget_window_days)->toBe(3);
});

test('categorizing in the inbox can save a payee rule', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->create([
        'payee' => 'Lokal Baker',
        'category' => null,
        'needs_review' => true,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::inbox')
        ->set('saveAsRule', true)
        ->call('assign', Category::Servering->value)
        ->assertHasNoErrors();

    $rule = $user->foodRules()->where('pattern', 'Lokal Baker')->first();

    expect($rule)->not->toBeNull()
        ->and($rule->effect)->toBe(FoodRuleEffect::Count)
        ->and($rule->category)->toBe(Category::Servering)
        ->and($rule->match_type)->toBe(FoodRuleMatch::Contains)
        ->and($rule->priority)->toBe(110)
        ->and($user->transactions()->first()->needs_review)->toBeFalse();
});

test('bank import recognizes groceries obs and express fuel', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $this->actingAs($user);

    $csv = implode("\n", [
        'Dato,Beløp,Tekst',
        '2026-03-04,100.00,REMA 1000 DIGERNES',
        '2026-03-04,50.00,Obs City Lade Trondheim',
        '2026-03-04,700.00,Express Malvik Svebergvegen',
        '2026-03-04,40.00,Circle K',
        '',
    ]);

    Livewire::test('pages::transactions.import')
        ->set('upload', UploadedFile::fake()->createWithContent('bank.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    expect($user->transactions()->where('payee', 'REMA 1000 DIGERNES')->first()->category)->toBe(Category::Dagligvarer)
        ->and($user->transactions()->where('payee', 'Obs City Lade Trondheim')->first()->category)->toBe(Category::Dagligvarer)
        ->and($user->transactions()->where('payee', 'Express Malvik Svebergvegen')->first()->category)->toBe(Category::Bil)
        ->and($user->transactions()->where('payee', 'Express Malvik Svebergvegen')->first()->needs_review)->toBeFalse()
        ->and($user->transactions()->where('payee', 'Circle K')->first()->needs_review)->toBeTrue()
        ->and($user->transactions()->where('payee', 'Circle K')->first()->category)->toBeNull();
});

test('the api returns the same food budget and can update review flags and rules', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $token = $user->createToken('Personlig regnskapsfører')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-09-01',
            'amount' => '120.00',
            'payee' => 'KIWI 512',
        ])
        ->assertCreated()
        ->assertJsonPath('category', 'Dagligvarer')
        ->assertJsonPath('needs_review', false);

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-09-01',
            'amount' => '45.00',
            'payee' => 'YX Slependen',
        ])
        ->assertCreated()
        ->assertJsonPath('category', null)
        ->assertJsonPath('needs_review', true);

    $this->withToken($token)
        ->postJson('/api/transactions', [
            'booked_on' => '2026-09-01',
            'amount' => '610.00',
            'payee' => 'Express Malvik Svebergvegen',
        ])
        ->assertCreated()
        ->assertJsonPath('category', 'Bil')
        ->assertJsonPath('needs_review', false);

    $pending = $user->transactions()->where('payee', 'YX Slependen')->first();

    $this->withToken($token)
        ->patchJson('/api/transactions/'.$pending->id, [
            'category' => 'Dagligvarer',
            'needs_review' => false,
        ])
        ->assertOk()
        ->assertJsonPath('category', 'Dagligvarer')
        ->assertJsonPath('needs_review', false);

    $this->withToken($token)
        ->getJson('/api/food-budget')
        ->assertOk()
        ->assertJsonPath('spent', '165.00')
        ->assertJsonPath('remaining_today', '1235.00')
        ->assertJsonPath('pending_review', '0.00')
        ->assertJsonPath('over_limit', false)
        ->assertJsonPath('timezone', 'Europe/Oslo');

    $this->withToken($token)
        ->getJson('/api/food-rules')
        ->assertOk()
        ->assertJsonFragment(['pattern' => 'Express', 'match_type' => 'starts_with', 'category' => 'Bil'])
        ->assertJsonFragment(['pattern' => 'Obs', 'effect' => 'count', 'category' => 'Dagligvarer'])
        ->assertJsonFragment(['pattern' => 'Obs Bygg', 'effect' => 'exclude'])
        ->assertJsonFragment(['pattern' => 'Circle K', 'effect' => 'review']);

    $this->withToken($token)
        ->postJson('/api/food-rules', [
            'pattern' => 'Nabolagsbakeriet',
            'effect' => 'count',
            'category' => 'Servering',
        ])
        ->assertCreated()
        ->assertJsonPath('priority', 110);

    $other = User::factory()->create();
    $foreign = $other->foodRules()->where('pattern', 'Kiwi')->first();

    $this->withToken($token)
        ->patchJson('/api/food-rules/'.$foreign->id, [
            'pattern' => 'Kapret',
            'effect' => 'count',
        ])
        ->assertForbidden();
});
