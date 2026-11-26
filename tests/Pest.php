<?php

use App\Enums\BankConnectionStatus;
use App\Enums\Category;
use App\Models\BankConnection;
use App\Models\PendingBankTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
