<?php

namespace App\Actions;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\Sparebank1\ParsedBankTransaction;
use Illuminate\Support\Facades\DB;

class ReplacePendingBankTransactions
{
    public function __construct(private ClassifyBankTransaction $classify) {}

    /**
     * Replace the account's reserved rows with what the bank reports now.
     * Rows are not matched against the previous sync, because the bank's ids
     * for reserved rows do not stay with one transaction.
     *
     * @param  list<ParsedBankTransaction>  $reserved
     * @param  list<string>  $ownAccountNumbers
     */
    public function __invoke(User $user, BankAccount $account, array $reserved, array $ownAccountNumbers): int
    {
        $seenAt = now();
        $rows = [];

        foreach ($reserved as $parsed) {
            $defaults = ($this->classify)($user, $account, $parsed, $ownAccountNumbers);

            $rows[] = [
                'user_id' => $user->id,
                'external_id' => $parsed->externalId,
                'booked_on' => $parsed->bookedOn->toDateString(),
                'amount' => $parsed->signedAmount,
                'payee' => $parsed->payee,
                'note' => $parsed->note,
                'type_text' => $parsed->typeText === null ? null : mb_substr($parsed->typeText, 0, 255),
                'remote_account_number' => $parsed->remoteAccountNumber,
                'suggested_category' => $defaults->category,
                'counts_as_food' => (float) $parsed->signedAmount < 0 && $defaults->countsAsFood(),
                'last_seen_at' => $seenAt,
            ];
        }

        DB::transaction(function () use ($account, $rows): void {
            $account->pendingTransactions()->delete();
            $account->pendingTransactions()->createMany($rows);
        });

        return count($rows);
    }
}
