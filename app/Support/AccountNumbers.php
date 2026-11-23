<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;

/**
 * The account numbers the bank sync knows for one user. An account is shown
 * as its name and its number, "Brukskonto SMN · 1234.56.78901", so two
 * accounts with similar names can be told apart. An account that is only
 * entered by hand has no number here.
 */
class AccountNumbers
{
    /**
     * @var array<int, string>
     */
    private array $byBankAccount = [];

    /**
     * @var array<string, list<string>>
     */
    private array $byName = [];

    public function __construct(User $user)
    {
        foreach (BankAccount::query()->where('user_id', $user->id)->get() as $account) {
            $number = $account->displayNumber();

            if ($number === '') {
                continue;
            }

            $this->byBankAccount[$account->id] = $number;

            if ($account->payment_method instanceof PaymentMethod) {
                $this->byName[$account->payment_method->value][] = $number;
            }
        }
    }

    public static function join(string $name, ?string $number): string
    {
        return $number === null || $number === '' ? $name : $name.' · '.$number;
    }

    /**
     * The number of the synced account that is mapped to this payment method.
     * When several synced accounts share the method, the name alone does not
     * say which one is meant, and there is no number to show.
     */
    public function forName(?string $name): ?string
    {
        $numbers = array_unique($this->byName[$name ?? ''] ?? []);

        return count($numbers) === 1 ? reset($numbers) : null;
    }

    /**
     * A bank row has the number of the account it was imported from, and a
     * split line that of its bank row. A row entered by hand has the number
     * its payment method is mapped to.
     */
    public function forTransaction(Transaction $transaction): ?string
    {
        $bankAccountId = $transaction->bank_account_id;

        if ($bankAccountId === null && $transaction->parent_id !== null) {
            $bankAccountId = $transaction->parent?->bank_account_id;
        }

        if ($bankAccountId !== null) {
            return $this->byBankAccount[$bankAccountId] ?? null;
        }

        return $this->forName($transaction->payment_method?->value);
    }

    public function label(PaymentMethod|string $name): string
    {
        $name = $name instanceof PaymentMethod ? $name->value : $name;

        return self::join($name, $this->forName($name));
    }

    public function transactionLabel(Transaction $transaction): ?string
    {
        if (! $transaction->payment_method instanceof PaymentMethod) {
            return null;
        }

        return self::join($transaction->payment_method->value, $this->forTransaction($transaction));
    }
}
