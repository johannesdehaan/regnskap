<?php

namespace App\Actions;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\Sparebank1\ParsedBankTransaction;
use App\Support\BankMovement;
use App\Support\BankRowDefaults;

class ClassifyBankTransaction
{
    public function __construct(
        private ClassifyFoodPurchase $classify,
        private BankMovement $movement,
    ) {}

    /**
     * @param  list<string>  $ownAccountNumbers
     */
    public function __invoke(User $user, BankAccount $account, ParsedBankTransaction $parsed, array $ownAccountNumbers): BankRowDefaults
    {
        if ($this->movement->isNonConsumption(
            $parsed->payee,
            $parsed->note,
            $parsed->typeText,
            $parsed->remoteAccountNumber,
            $account->account_type,
            $ownAccountNumbers,
        )) {
            return new BankRowDefaults(null, false, true);
        }

        $classification = ($this->classify)($user, $parsed->payee, $parsed->note);

        return new BankRowDefaults($classification->category, $classification->needsReview, false);
    }
}
