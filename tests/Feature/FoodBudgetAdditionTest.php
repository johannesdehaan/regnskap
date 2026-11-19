<?php

use App\Actions\BuildFoodBudget;
use App\Enums\Category;
use App\Models\FoodBudgetAddition;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function budgetAddition(User $user, string $from, string $to, string $perDay = '40.00', string $label = 'Gjester på besøk'): FoodBudgetAddition
{
    return FoodBudgetAddition::factory()->for($user)->create([
        'label' => $label,
        'starts_on' => $from,
        'ends_on' => $to,
        'amount_per_day' => $perDay,
    ]);
}

function groceries(User $user, string $amount, string $date): Transaction
{
    return Transaction::factory()->for($user)->create([
        'payee' => 'Testbutikk',
        'note' => null,
        'amount' => $amount,
        'booked_on' => $date,
        'category' => Category::Dagligvarer,
        'needs_review' => false,
        'exclude_from_budget' => false,
        'payment_method' => null,
    ]);
}

test('without additions the effective amount is the base amount', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    groceries($user, '200.00', '2026-03-10');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['amount'])->toBe('1400.00')
        ->and($status['additions_total'])->toBe('0.00')
        ->and($status['effective_amount'])->toBe('1400.00')
        ->and($status['additions'])->toBe([])
        ->and($status['remaining_today'])->toBe('1200.00')
        ->and(array_column($status['days'], 'addition'))->toBe(array_fill(0, 7, '0.00'))
        ->and(array_column($status['rolloff'], 'effective_amount'))->toBe(array_fill(0, 7, '1400.00'));
});

test('an addition that started inside the window counts only the days so far', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $addition = budgetAddition($user, '2026-03-08', '2026-03-15');
    groceries($user, '200.00', '2026-03-09');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['amount'])->toBe('1400.00')
        ->and($status['additions_total'])->toBe('120.00')
        ->and($status['effective_amount'])->toBe('1520.00')
        ->and($status['spent'])->toBe('200.00')
        ->and($status['remaining_today'])->toBe('1320.00')
        ->and($status['over_limit'])->toBeFalse()
        ->and($status['additions'])->toBe([[
            'id' => $addition->id,
            'label' => 'Gjester på besøk',
            'starts_on' => '2026-03-08',
            'ends_on' => '2026-03-15',
            'amount_per_day' => '40.00',
            'days_in_window' => 3,
            'amount_in_window' => '120.00',
        ]])
        ->and(array_column($status['days'], 'addition', 'date'))->toBe([
            '2026-03-04' => '0.00',
            '2026-03-05' => '0.00',
            '2026-03-06' => '0.00',
            '2026-03-07' => '0.00',
            '2026-03-08' => '40.00',
            '2026-03-09' => '40.00',
            '2026-03-10' => '40.00',
        ]);
});

test('an addition that started before the window counts only the days still inside', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    budgetAddition($user, '2026-03-01', '2026-03-05');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['additions_total'])->toBe('80.00')
        ->and($status['effective_amount'])->toBe('1480.00')
        ->and($status['remaining_today'])->toBe('1480.00')
        ->and($status['additions'][0]['days_in_window'])->toBe(2)
        ->and($status['additions'][0]['amount_in_window'])->toBe('80.00')
        ->and($status['days'][0]['addition'])->toBe('40.00')
        ->and($status['days'][1]['addition'])->toBe('40.00')
        ->and($status['days'][2]['addition'])->toBe('0.00');
});

test('an addition longer than the window counts every window day once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    budgetAddition($user, '2026-02-01', '2026-04-30');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['additions_total'])->toBe('280.00')
        ->and($status['effective_amount'])->toBe('1680.00')
        ->and($status['additions'][0]['days_in_window'])->toBe(7)
        ->and(array_column($status['rolloff'], 'effective_amount'))->toBe(array_fill(0, 7, '1680.00'));
});

test('overlapping additions add up on the days they share', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $long = budgetAddition($user, '2026-03-08', '2026-03-12', '40.00', 'Gjester på besøk');
    $short = budgetAddition($user, '2026-03-10', '2026-03-10', '25.50', 'Bursdagsmiddag');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['additions_total'])->toBe('145.50')
        ->and($status['effective_amount'])->toBe('1545.50')
        ->and($status['remaining_today'])->toBe('1545.50')
        ->and($status['days'][4]['addition'])->toBe('40.00')
        ->and($status['days'][5]['addition'])->toBe('40.00')
        ->and($status['days'][6]['addition'])->toBe('65.50')
        ->and(array_column($status['additions'], 'id'))->toBe([$long->id, $short->id])
        ->and(array_column($status['additions'], 'days_in_window'))->toBe([3, 1])
        ->and(array_column($status['additions'], 'amount_in_window'))->toBe(['120.00', '25.50']);
});

test('an ended addition counts until its last day leaves the window', function () {
    $user = User::factory()->create();
    budgetAddition($user, '2026-03-01', '2026-03-04');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 23:59:00', 'Europe/Oslo'));
    $lastDay = app(BuildFoodBudget::class)($user);

    $this->travelTo(CarbonImmutable::parse('2026-03-11 00:01:00', 'Europe/Oslo'));
    $dayAfter = app(BuildFoodBudget::class)($user);

    expect($lastDay['days'][0]['date'])->toBe('2026-03-04')
        ->and($lastDay['additions_total'])->toBe('40.00')
        ->and($lastDay['effective_amount'])->toBe('1440.00')
        ->and($lastDay['additions'][0]['days_in_window'])->toBe(1)
        ->and($lastDay['rolloff'][0]['effective_amount'])->toBe('1400.00')
        ->and($dayAfter['days'][0]['date'])->toBe('2026-03-05')
        ->and($dayAfter['additions_total'])->toBe('0.00')
        ->and($dayAfter['effective_amount'])->toBe('1400.00')
        ->and($dayAfter['remaining_today'])->toBe('1400.00')
        ->and($dayAfter['additions'])->toBe([]);
});

test('a future addition does not count until its first day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    budgetAddition($user, '2026-03-11', '2026-03-12');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['additions_total'])->toBe('0.00')
        ->and($status['effective_amount'])->toBe('1400.00')
        ->and($status['additions'])->toBe([])
        ->and($status['rolloff'][0]['date'])->toBe('2026-03-11')
        ->and($status['rolloff'][0]['additions_total'])->toBe('40.00')
        ->and($status['rolloff'][0]['effective_amount'])->toBe('1440.00')
        ->and($status['rolloff'][0]['remaining_if_no_new_spending'])->toBe('1440.00')
        ->and($status['rolloff'][1]['effective_amount'])->toBe('1480.00')
        ->and($status['rolloff'][6]['date'])->toBe('2026-03-17')
        ->and($status['rolloff'][6]['effective_amount'])->toBe('1480.00');
});

test('roll-off lowers the limit when an addition day falls out with its spending', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    budgetAddition($user, '2026-03-04', '2026-03-05');
    groceries($user, '200.00', '2026-03-04');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['effective_amount'])->toBe('1480.00')
        ->and($status['remaining_today'])->toBe('1280.00')
        ->and($status['rolloff'][0])->toBe([
            'date' => '2026-03-11',
            'drops' => '2026-03-04',
            'frees' => '200.00',
            'additions_total' => '40.00',
            'effective_amount' => '1440.00',
            'remaining_if_no_new_spending' => '1440.00',
        ])
        ->and($status['rolloff'][1])->toBe([
            'date' => '2026-03-12',
            'drops' => '2026-03-05',
            'frees' => '0.00',
            'additions_total' => '0.00',
            'effective_amount' => '1400.00',
            'remaining_if_no_new_spending' => '1400.00',
        ]);
});

test('the remainder and over_limit use the effective amount', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-02 08:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    groceries($user, '1450.00', '2026-04-02');

    $without = app(BuildFoodBudget::class)($user);

    budgetAddition($user, '2026-04-01', '2026-04-02');
    $with = app(BuildFoodBudget::class)($user);

    groceries($user, '50.00', '2026-04-02');
    $overAgain = app(BuildFoodBudget::class)($user);

    expect($without['remaining_today'])->toBe('-50.00')
        ->and($without['over_limit'])->toBeTrue()
        ->and($with['amount'])->toBe('1400.00')
        ->and($with['effective_amount'])->toBe('1480.00')
        ->and($with['remaining_today'])->toBe('30.00')
        ->and($with['over_limit'])->toBeFalse()
        ->and($overAgain['remaining_today'])->toBe('-20.00')
        ->and($overAgain['over_limit'])->toBeTrue();
});

test('addition days follow Europe/Oslo around midnight utc', function () {
    $user = User::factory()->create();
    budgetAddition($user, '2026-01-16', '2026-01-20');

    $this->travelTo(CarbonImmutable::parse('2026-01-15 22:30:00', 'UTC'));
    $before = app(BuildFoodBudget::class)($user);

    $this->travelTo(CarbonImmutable::parse('2026-01-15 23:30:00', 'UTC'));
    $after = app(BuildFoodBudget::class)($user);

    expect($before['today'])->toBe('2026-01-15')
        ->and($before['effective_amount'])->toBe('1400.00')
        ->and($before['rolloff'][0]['effective_amount'])->toBe('1440.00')
        ->and($after['today'])->toBe('2026-01-16')
        ->and($after['timezone'])->toBe('Europe/Oslo')
        ->and($after['effective_amount'])->toBe('1440.00')
        ->and($after['additions'][0]['days_in_window'])->toBe(1);
});

test('the clock change does not skip or repeat an addition day', function () {
    $user = User::factory()->create();
    budgetAddition($user, '2026-03-28', '2026-03-30');
    budgetAddition($user, '2026-10-24', '2026-10-26');

    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00', 'Europe/Oslo'));
    $spring = app(BuildFoodBudget::class)($user);

    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'Europe/Oslo'));
    $autumn = app(BuildFoodBudget::class)($user);

    expect($spring['additions_total'])->toBe('120.00')
        ->and($spring['additions'][0]['days_in_window'])->toBe(3)
        ->and(array_column($spring['days'], 'date'))->toBe([
            '2026-03-25', '2026-03-26', '2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31',
        ])
        ->and($autumn['additions_total'])->toBe('120.00')
        ->and($autumn['additions'][0]['days_in_window'])->toBe(3)
        ->and(array_column($autumn['days'], 'date'))->toBe([
            '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27',
        ]);
});

test('the window length decides how many addition days count', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create(['food_budget_amount' => '600.00', 'food_budget_window_days' => 3]);
    budgetAddition($user, '2026-03-01', '2026-03-09');

    $status = app(BuildFoodBudget::class)($user);

    expect($status['window_days'])->toBe(3)
        ->and($status['amount'])->toBe('600.00')
        ->and($status['additions_total'])->toBe('80.00')
        ->and($status['effective_amount'])->toBe('680.00')
        ->and($status['additions'][0]['days_in_window'])->toBe(2)
        ->and($status['rolloff'][2]['effective_amount'])->toBe('600.00');
});

test('additions belong to one user and are removed with the user', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = budgetAddition($other, '2026-03-08', '2026-03-12');

    expect(app(BuildFoodBudget::class)($user)['effective_amount'])->toBe('1400.00')
        ->and(app(BuildFoodBudget::class)($other)['effective_amount'])->toBe('1520.00')
        ->and($user->can('view', $theirs))->toBeFalse()
        ->and($user->can('update', $theirs))->toBeFalse()
        ->and($user->can('delete', $theirs))->toBeFalse()
        ->and($other->can('update', $theirs))->toBeTrue();

    $other->delete();

    expect(FoodBudgetAddition::query()->count())->toBe(0);
});

test('guests cannot use the additions api', function () {
    $addition = budgetAddition(User::factory()->create(), '2026-03-08', '2026-03-12');

    $this->getJson('/api/food-budget/additions')->assertUnauthorized();
    $this->postJson('/api/food-budget/additions', [
        'label' => 'Gjester på besøk',
        'starts_on' => '2026-03-08',
        'ends_on' => '2026-03-12',
        'amount_per_day' => '40.00',
    ])->assertUnauthorized();
    $this->patchJson('/api/food-budget/additions/'.$addition->id, ['label' => 'Kapret'])->assertUnauthorized();
    $this->deleteJson('/api/food-budget/additions/'.$addition->id)->assertUnauthorized();

    expect($addition->fresh()->label)->toBe('Gjester på besøk');
});

test('the api creates lists updates and deletes additions and the budget follows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-03 10:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $token = $user->createToken('Personlig regnskapsfører')->plainTextToken;
    groceries($user, '300.00', '2026-09-03');

    $id = $this->withToken($token)
        ->postJson('/api/food-budget/additions', [
            'label' => 'Gjester på besøk',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-08',
            'amount_per_day' => 40,
        ])
        ->assertCreated()
        ->assertJsonPath('label', 'Gjester på besøk')
        ->assertJsonPath('starts_on', '2026-09-01')
        ->assertJsonPath('ends_on', '2026-09-08')
        ->assertJsonPath('amount_per_day', '40.00')
        ->json('id');

    $this->withToken($token)
        ->getJson('/api/food-budget/additions')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $id)
        ->assertJsonPath('0.starts_on', '2026-09-01')
        ->assertJsonPath('0.amount_per_day', '40.00');

    $this->withToken($token)
        ->getJson('/api/food-budget')
        ->assertOk()
        ->assertJsonPath('amount', '1400.00')
        ->assertJsonPath('additions_total', '120.00')
        ->assertJsonPath('effective_amount', '1520.00')
        ->assertJsonPath('spent', '300.00')
        ->assertJsonPath('remaining_today', '1220.00')
        ->assertJsonPath('over_limit', false)
        ->assertJsonCount(1, 'additions')
        ->assertJsonPath('additions.0.id', $id)
        ->assertJsonPath('additions.0.label', 'Gjester på besøk')
        ->assertJsonPath('additions.0.starts_on', '2026-09-01')
        ->assertJsonPath('additions.0.ends_on', '2026-09-08')
        ->assertJsonPath('additions.0.amount_per_day', '40.00')
        ->assertJsonPath('additions.0.days_in_window', 3)
        ->assertJsonPath('additions.0.amount_in_window', '120.00')
        ->assertJsonPath('days.6.addition', '40.00')
        ->assertJsonPath('rolloff.0.effective_amount', '1560.00');

    $this->withToken($token)
        ->patchJson('/api/food-budget/additions/'.$id, [
            'ends_on' => '2026-09-02',
            'amount_per_day' => '55.5',
        ])
        ->assertOk()
        ->assertJsonPath('label', 'Gjester på besøk')
        ->assertJsonPath('starts_on', '2026-09-01')
        ->assertJsonPath('ends_on', '2026-09-02')
        ->assertJsonPath('amount_per_day', '55.50');

    $this->withToken($token)
        ->getJson('/api/food-budget')
        ->assertOk()
        ->assertJsonPath('effective_amount', '1511.00')
        ->assertJsonPath('additions.0.days_in_window', 2);

    $this->withToken($token)
        ->patchJson('/api/food-budget', ['amount' => 1000])
        ->assertOk()
        ->assertJsonPath('amount', '1000.00')
        ->assertJsonPath('effective_amount', '1111.00');

    $this->withToken($token)
        ->deleteJson('/api/food-budget/additions/'.$id)
        ->assertNoContent();

    $this->withToken($token)
        ->getJson('/api/food-budget/additions')
        ->assertOk()
        ->assertJsonCount(0);

    $this->withToken($token)
        ->getJson('/api/food-budget')
        ->assertOk()
        ->assertJsonPath('amount', '1000.00')
        ->assertJsonPath('additions_total', '0.00')
        ->assertJsonPath('effective_amount', '1000.00')
        ->assertJsonPath('additions', []);

    expect($user->fresh()->food_budget_amount)->toBe('1000.00');
});

test('the api rejects additions with bad dates or amounts', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $valid = [
        'label' => 'Gjester på besøk',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-08',
        'amount_per_day' => '40.00',
    ];

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['label', 'starts_on', 'ends_on', 'amount_per_day']);

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [...$valid, 'ends_on' => '2026-08-31'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_on']);

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [...$valid, 'starts_on' => '2026-02-30'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on']);

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [...$valid, 'ends_on' => '08.09.2026'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_on']);

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [...$valid, 'amount_per_day' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount_per_day']);

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [...$valid, 'amount_per_day' => -40])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount_per_day']);

    expect($user->foodBudgetAdditions()->count())->toBe(0);

    $addition = budgetAddition($user, '2026-09-01', '2026-09-08');

    $this->withToken($token)
        ->patchJson('/api/food-budget/additions/'.$addition->id, ['ends_on' => '2026-08-31'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_on']);

    $this->withToken($token)
        ->patchJson('/api/food-budget/additions/'.$addition->id, ['starts_on' => '2026-09-09'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_on']);

    expect($addition->fresh()->starts_on->toDateString())->toBe('2026-09-01')
        ->and($addition->fresh()->ends_on->toDateString())->toBe('2026-09-08');

    $this->withToken($token)
        ->postJson('/api/food-budget/additions', [...$valid, 'ends_on' => '2026-09-01'])
        ->assertCreated()
        ->assertJsonPath('starts_on', '2026-09-01')
        ->assertJsonPath('ends_on', '2026-09-01');
});

test('a token cannot see or change another users addition', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    budgetAddition($user, '2026-09-01', '2026-09-08', '40.00', 'Mitt tillegg');
    $theirs = budgetAddition($other, '2026-09-01', '2026-09-08', '40.00', 'Fremmed tillegg');

    $this->withToken($token)
        ->getJson('/api/food-budget/additions')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['label' => 'Mitt tillegg'])
        ->assertJsonMissing(['label' => 'Fremmed tillegg']);

    $this->withToken($token)
        ->patchJson('/api/food-budget/additions/'.$theirs->id, ['label' => 'Kapret'])
        ->assertForbidden();

    $this->withToken($token)
        ->deleteJson('/api/food-budget/additions/'.$theirs->id)
        ->assertForbidden();

    expect($theirs->fresh())->not->toBeNull()
        ->and($theirs->fresh()->label)->toBe('Fremmed tillegg');
});

test('the food budget page and the inbox show the effective limit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    budgetAddition($user, '2026-03-08', '2026-03-15');
    budgetAddition($user, '2026-03-20', '2026-03-21', '30.00', 'Påskelunsj');
    budgetAddition($user, '2026-02-01', '2026-02-02', '30.00', 'Vinterferie');
    groceries($user, '200.00', '2026-03-10');

    $this->actingAs($user);

    Livewire::test('pages::mat.index')
        ->assertSee('Med 120 kr i tillegg er grensen 1 520 kr i dag.')
        ->assertSee('1 400 kr + 120 kr i tillegg')
        ->assertSee('1 320')
        ->assertSee('Under grensen')
        ->assertSee('Gjester på besøk')
        ->assertSee('+ 40 kr per dag')
        ->assertSee('8. mar – 15. mar')
        ->assertSee('120 kr i dagens grense')
        ->assertSee('3 dager i vinduet')
        ->assertSee('+ 40 kr i tillegg')
        ->assertSee('Grensen er da 1 560 kr.')
        ->assertSeeInOrder(['Påskelunsj', 'Ikke startet', 'Gjester på besøk', 'Vinterferie', 'Avsluttet']);

    Livewire::test('pages::inbox')
        ->assertSee('1 320')
        ->assertSee('1 400 kr + 120 kr i tillegg');
});

test('the food budget page hides the addition texts when there are none', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::mat.index')
        ->assertSee('1 400 kr per 7 dager')
        ->assertSee('Ingen tillegg ennå.')
        ->assertDontSee('i tillegg')
        ->assertDontSee('Grensen er da');
});

test('a user can add edit and delete an addition on the food budget page', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Oslo'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $page = Livewire::test('pages::mat.index')
        ->set('additionLabel', 'Helgebesøk')
        ->set('additionStartsOn', '2026-03-09')
        ->set('additionEndsOn', '2026-03-12')
        ->set('additionAmountPerDay', '40')
        ->call('saveAddition')
        ->assertHasNoErrors()
        ->assertSet('additionLabel', '')
        ->assertSet('additionStartsOn', '')
        ->assertSee('Helgebesøk')
        ->assertSee('1 480');

    $addition = $user->foodBudgetAdditions()->sole();

    expect($addition->label)->toBe('Helgebesøk')
        ->and($addition->starts_on->toDateString())->toBe('2026-03-09')
        ->and($addition->ends_on->toDateString())->toBe('2026-03-12')
        ->and($addition->amount_per_day)->toBe('40.00');

    $page->call('editAddition', $addition->id)
        ->assertSet('editingAdditionId', $addition->id)
        ->assertSet('additionLabel', 'Helgebesøk')
        ->assertSet('additionStartsOn', '2026-03-09')
        ->assertSet('additionEndsOn', '2026-03-12')
        ->assertSet('additionAmountPerDay', '40.00')
        ->set('additionStartsOn', '2026-03-08')
        ->set('additionAmountPerDay', '50')
        ->call('saveAddition')
        ->assertHasNoErrors()
        ->assertSet('editingAdditionId', null)
        ->assertSee('1 550');

    expect($user->foodBudgetAdditions()->count())->toBe(1)
        ->and($addition->fresh()->starts_on->toDateString())->toBe('2026-03-08')
        ->and($addition->fresh()->amount_per_day)->toBe('50.00')
        ->and($user->fresh()->food_budget_amount)->toBe('1400.00');

    $page->call('deleteAddition', $addition->id)
        ->assertSee('Ingen tillegg ennå.')
        ->assertDontSee('Helgebesøk');

    expect($user->foodBudgetAdditions()->count())->toBe(0);
});

test('the food budget page rejects an end date before the start date', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::mat.index')
        ->set('additionLabel', 'Gjester på besøk')
        ->set('additionStartsOn', '2026-03-09')
        ->set('additionEndsOn', '2026-03-08')
        ->set('additionAmountPerDay', '40')
        ->call('saveAddition')
        ->assertHasErrors(['additionEndsOn'])
        ->assertSee('Sluttdatoen kan ikke være før startdatoen.');

    Livewire::test('pages::mat.index')
        ->set('additionLabel', 'Gjester på besøk')
        ->set('additionStartsOn', '2026-03-09')
        ->set('additionEndsOn', '2026-03-09')
        ->set('additionAmountPerDay', '0')
        ->call('saveAddition')
        ->assertHasErrors(['additionAmountPerDay']);

    expect($user->foodBudgetAdditions()->count())->toBe(0);
});

test('a user cannot edit or delete another users addition on the food budget page', function () {
    $user = User::factory()->create();
    $theirs = budgetAddition(User::factory()->create(), '2026-03-08', '2026-03-12', '40.00', 'Fremmed tillegg');

    $this->actingAs($user);

    Livewire::test('pages::mat.index')
        ->assertDontSee('Fremmed tillegg')
        ->call('editAddition', $theirs->id)
        ->assertForbidden();

    Livewire::test('pages::mat.index')
        ->call('deleteAddition', $theirs->id)
        ->assertForbidden();

    Livewire::test('pages::mat.index')
        ->set('editingAdditionId', $theirs->id)
        ->set('additionLabel', 'Kapret')
        ->set('additionStartsOn', '2026-03-09')
        ->set('additionEndsOn', '2026-03-09')
        ->set('additionAmountPerDay', '1')
        ->call('saveAddition')
        ->assertForbidden();

    expect($theirs->fresh())->not->toBeNull()
        ->and($theirs->fresh()->label)->toBe('Fremmed tillegg');
});
