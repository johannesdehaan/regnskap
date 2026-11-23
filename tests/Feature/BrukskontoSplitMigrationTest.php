<?php

use App\Enums\BankConnectionStatus;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\RecurringCost;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

test('rows from the synced account become Brukskonto SMN and the rest Brukskonto Hegra', function () {
    $user = User::factory()->create();
    $synced = sharedNameBankAccount($user, 'smn-1', '12000000011', 'Brukskonto');
    $salary = sharedNameBankAccount($user, 'smn-2', '12000000022', PaymentMethod::LonnskontoSparebank1->value);

    $bankRow = sharedNameTransaction($user, 'Brukskonto', ['bank_account_id' => $synced->id]);
    $splitBankRow = sharedNameTransaction($user, 'Brukskonto', ['bank_account_id' => $synced->id]);
    $bankLine = sharedNameTransaction($user, 'Brukskonto', ['parent_id' => $splitBankRow->id]);
    $relabelled = sharedNameTransaction($user, PaymentMethod::Kredittkort->value, ['bank_account_id' => $synced->id]);

    $manual = sharedNameTransaction($user, 'Brukskonto');
    $splitManual = sharedNameTransaction($user, 'Brukskonto');
    $manualLine = sharedNameTransaction($user, 'Brukskonto', ['parent_id' => $splitManual->id]);
    $salaryRow = sharedNameTransaction($user, PaymentMethod::LonnskontoSparebank1->value, ['bank_account_id' => $salary->id]);
    $salaryRowSetByHand = sharedNameTransaction($user, 'Brukskonto', ['bank_account_id' => $salary->id]);
    $unset = sharedNameTransaction($user, null);

    $fixedCost = sharedNameRecurringCost($user, 'Brukskonto');
    $cardCost = sharedNameRecurringCost($user, 'Kredittkort');

    $listed = Account::factory()->for($user)->create(['name' => 'Brukskonto', 'type' => 'brukskonto']);
    $savings = Account::factory()->for($user)->create(['name' => 'Sparekonto', 'type' => 'sparekonto']);

    brukskontoSplitMigration()->up();

    expect(sharedNameMethod($bankRow))->toBe('Brukskonto SMN')
        ->and(sharedNameMethod($splitBankRow))->toBe('Brukskonto SMN')
        ->and(sharedNameMethod($bankLine))->toBe('Brukskonto SMN')
        ->and(sharedNameMethod($relabelled))->toBe('Kredittkort')
        ->and(sharedNameMethod($manual))->toBe('Brukskonto Hegra')
        ->and(sharedNameMethod($splitManual))->toBe('Brukskonto Hegra')
        ->and(sharedNameMethod($manualLine))->toBe('Brukskonto Hegra')
        ->and(sharedNameMethod($salaryRow))->toBe('Lønnskonto Sparebank 1')
        ->and(sharedNameMethod($salaryRowSetByHand))->toBe('Brukskonto Hegra')
        ->and(sharedNameMethod($unset))->toBeNull();

    expect($synced->fresh()->payment_method)->toBe(PaymentMethod::BrukskontoSmn)
        ->and($salary->fresh()->payment_method)->toBe(PaymentMethod::LonnskontoSparebank1)
        ->and($synced->fresh()->account_number)->toBe('12000000011');

    expect($fixedCost->fresh()->payment_method)->toBe('Brukskonto Hegra')
        ->and($cardCost->fresh()->payment_method)->toBe('Kredittkort');

    expect($listed->fresh()->name)->toBe('Brukskonto Hegra')
        ->and($savings->fresh()->name)->toBe('Sparekonto')
        ->and($user->accounts()->orderBy('name')->pluck('type', 'name')->all())->toBe([
            'Brukskonto Hegra' => 'brukskonto',
            'Brukskonto SMN' => 'brukskonto',
            'Sparekonto' => 'sparekonto',
        ]);

    expect($bankRow->fresh()->payment_method)->toBe(PaymentMethod::BrukskontoSmn)
        ->and($manual->fresh()->payment_method)->toBe(PaymentMethod::BrukskontoHegra)
        ->and($bankRow->fresh()->updated_at->equalTo($bankRow->updated_at))->toBeTrue();
});

test('without a synced Brukskonto everything becomes Brukskonto Hegra', function () {
    $user = User::factory()->create();
    $card = sharedNameBankAccount($user, 'card-1', '12000000033', PaymentMethod::Kredittkort->value);

    $manual = sharedNameTransaction($user, 'Brukskonto');
    $cardRow = sharedNameTransaction($user, PaymentMethod::Kredittkort->value, ['bank_account_id' => $card->id]);
    $listed = Account::factory()->for($user)->create(['name' => 'Brukskonto', 'type' => 'brukskonto']);

    brukskontoSplitMigration()->up();

    expect(sharedNameMethod($manual))->toBe('Brukskonto Hegra')
        ->and(sharedNameMethod($cardRow))->toBe('Kredittkort')
        ->and($card->fresh()->payment_method)->toBe(PaymentMethod::Kredittkort)
        ->and($listed->fresh()->name)->toBe('Brukskonto Hegra')
        ->and($user->accounts()->count())->toBe(1);
});

test('the SMN account is only listed for a user who lists the Hegra account', function () {
    $lists = User::factory()->create();
    $listsNothing = User::factory()->create();

    sharedNameBankAccount($lists, 'smn-1', '12000000011', 'Brukskonto');
    sharedNameBankAccount($listsNothing, 'smn-1', '12000000044', 'Brukskonto');
    Account::factory()->for($lists)->create(['name' => 'Brukskonto', 'type' => 'annet']);
    Account::factory()->for($listsNothing)->create(['name' => 'Sparekonto', 'type' => 'sparekonto']);

    brukskontoSplitMigration()->up();

    expect($lists->accounts()->orderBy('name')->pluck('type', 'name')->all())->toBe([
        'Brukskonto Hegra' => 'annet',
        'Brukskonto SMN' => 'annet',
    ])->and($listsNothing->accounts()->pluck('name')->all())->toBe(['Sparekonto']);
});

test('running the split again changes nothing', function () {
    $user = User::factory()->create();
    $synced = sharedNameBankAccount($user, 'smn-1', '12000000011', 'Brukskonto');

    sharedNameTransaction($user, 'Brukskonto', ['bank_account_id' => $synced->id]);
    sharedNameTransaction($user, 'Brukskonto');
    sharedNameRecurringCost($user, 'Brukskonto');
    Account::factory()->for($user)->create(['name' => 'Brukskonto', 'type' => 'brukskonto']);

    brukskontoSplitMigration()->up();
    $first = sharedNameSnapshot();

    brukskontoSplitMigration()->up();

    expect(sharedNameSnapshot())->toBe($first)
        ->and($user->accounts()->where('name', 'Brukskonto SMN')->count())->toBe(1)
        ->and(DB::table('transactions')->where('payment_method', 'Brukskonto')->exists())->toBeFalse();
});

test('a row left under the old name on an already renamed account still becomes SMN', function () {
    $user = User::factory()->create();
    $synced = sharedNameBankAccount($user, 'smn-1', '12000000011', PaymentMethod::BrukskontoSmn->value);
    $leftover = sharedNameTransaction($user, 'Brukskonto', ['bank_account_id' => $synced->id]);

    brukskontoSplitMigration()->up();

    expect(sharedNameMethod($leftover))->toBe('Brukskonto SMN');
});

test('rolling back gives both accounts the shared name, and the split can be made again', function () {
    $user = User::factory()->create();
    $synced = sharedNameBankAccount($user, 'smn-1', '12000000011', 'Brukskonto');

    $bankRow = sharedNameTransaction($user, 'Brukskonto', ['bank_account_id' => $synced->id]);
    $bankLine = sharedNameTransaction($user, 'Brukskonto', ['parent_id' => $bankRow->id]);
    $manual = sharedNameTransaction($user, 'Brukskonto');
    $fixedCost = sharedNameRecurringCost($user, 'Brukskonto');
    Account::factory()->for($user)->create(['name' => 'Brukskonto', 'type' => 'brukskonto']);
    Account::factory()->for($user)->create(['name' => 'Sparekonto', 'type' => 'sparekonto']);

    $before = sharedNameSnapshot();

    brukskontoSplitMigration()->up();
    $split = sharedNameSnapshot();

    brukskontoSplitMigration()->down();

    expect(sharedNameSnapshot())->toBe($before)
        ->and(sharedNameMethod($bankRow))->toBe('Brukskonto')
        ->and(sharedNameMethod($bankLine))->toBe('Brukskonto')
        ->and(sharedNameMethod($manual))->toBe('Brukskonto')
        ->and(DB::table('bank_accounts')->where('id', $synced->id)->value('payment_method'))->toBe('Brukskonto')
        ->and(DB::table('recurring_costs')->where('id', $fixedCost->id)->value('payment_method'))->toBe('Brukskonto')
        ->and($user->accounts()->orderBy('name')->pluck('name')->all())->toBe(['Brukskonto', 'Sparekonto']);

    brukskontoSplitMigration()->up();

    expect(sharedNameSnapshot())->toBe($split);
});

test('rolling back keeps an SMN account that is listed on its own', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Brukskonto SMN', 'type' => 'brukskonto']);

    brukskontoSplitMigration()->down();

    expect($user->accounts()->pluck('name')->all())->toBe(['Brukskonto']);
});

function brukskontoSplitMigration(): Migration
{
    return require database_path('migrations/2026_11_23_120000_split_brukskonto_into_hegra_and_smn.php');
}

/**
 * A synced account with a made-up number. The payment method is written
 * straight to the table, because the old shared name is no longer an enum case.
 */
function sharedNameBankAccount(User $user, string $key, string $number, ?string $method): BankAccount
{
    $connection = BankConnection::query()->firstOrCreate(
        ['user_id' => $user->id],
        ['status' => BankConnectionStatus::Connected],
    );

    $account = BankAccount::query()->create([
        'bank_connection_id' => $connection->id,
        'user_id' => $user->id,
        'external_key' => $key,
        'name' => 'Brukskonto',
        'account_number' => $number,
        'account_type' => 'deposit',
        'currency_code' => 'NOK',
        'sync_enabled' => true,
    ]);

    DB::table('bank_accounts')->where('id', $account->id)->update(['payment_method' => $method]);

    return $account;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function sharedNameTransaction(User $user, ?string $method, array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->for($user)->create(array_merge(['payment_method' => null], $attributes));

    DB::table('transactions')->where('id', $transaction->id)->update(['payment_method' => $method]);

    return $transaction;
}

function sharedNameRecurringCost(User $user, string $method): RecurringCost
{
    return RecurringCost::factory()->for($user)->create(['payment_method' => $method]);
}

function sharedNameMethod(Transaction $transaction): ?string
{
    $method = DB::table('transactions')->where('id', $transaction->id)->value('payment_method');

    return is_string($method) ? $method : null;
}

/**
 * Every place the name is stored, as it stands in the database.
 *
 * @return array<string, array<int, string|null>>
 */
function sharedNameSnapshot(): array
{
    return [
        'transactions' => DB::table('transactions')->orderBy('id')->pluck('payment_method', 'id')->all(),
        'bank_accounts' => DB::table('bank_accounts')->orderBy('id')->pluck('payment_method', 'id')->all(),
        'recurring_costs' => DB::table('recurring_costs')->orderBy('id')->pluck('payment_method', 'id')->all(),
        'accounts' => DB::table('accounts')->orderBy('name')->pluck('type', 'name')->all(),
    ];
}
