<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sparebank1\ParsedBankTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

class RecordSparebank1Transaction
{
    public function __construct(private ClassifyBankTransaction $classify) {}

    /**
     * Record a row the bank has booked. Reserved rows never become
     * transactions; they go to ReplacePendingBankTransactions.
     *
     * @param  list<string>  $ownAccountNumbers
     * @return 'imported'|'updated'|'skipped'
     */
    public function __invoke(User $user, BankAccount $account, ParsedBankTransaction $parsed, array $ownAccountNumbers): string
    {
        if ($parsed->bookingStatus !== BookingStatus::Booked) {
            throw new LogicException('Only booked bank rows can be recorded as transactions.');
        }

        $existing = $this->findExisting($user, $parsed);

        if ($existing instanceof Transaction) {
            return $this->update($account, $existing, $parsed);
        }

        try {
            $user->transactions()->create($this->attributes($user, $account, $parsed, $ownAccountNumbers));
        } catch (UniqueConstraintViolationException) {
            return 'skipped';
        }

        return 'imported';
    }

    /**
     * Refresh what the bank reports on a row that is already imported.
     * Category, subcategory, note, payment method, exclude_from_budget and
     * needs_review are set when the row is created and belong to whoever
     * edits it afterwards, so they are never derived again here. The one
     * exception is a split whose bank amount changes: its lines no longer
     * add up, so the parent goes back to the inbox.
     *
     * @return 'updated'|'skipped'
     */
    private function update(BankAccount $account, Transaction $existing, ParsedBankTransaction $parsed): string
    {
        $amountChanged = $this->toCents((string) $existing->amount) !== $this->toCents($parsed->absoluteAmount);
        $sameBankId = $existing->external_id === $parsed->externalId;

        if ($sameBankId && ! $amountChanged) {
            return 'skipped';
        }

        $hasSplit = $existing->children()->exists();

        if ($sameBankId && ! $hasSplit) {
            return 'skipped';
        }

        $existing->fill([
            'external_id' => $parsed->externalId,
            'dedupe_key' => $parsed->dedupeKey,
            'bank_account_id' => $account->id,
            'booked_on' => $parsed->bookedOn->toDateString(),
            'amount' => $parsed->absoluteAmount,
            'payee' => $parsed->payee,
        ]);

        if ($hasSplit && $amountChanged) {
            $existing->needs_review = true;
        }

        if (! $existing->isDirty()) {
            return 'skipped';
        }

        try {
            $existing->save();
        } catch (UniqueConstraintViolationException) {
            return 'skipped';
        }

        return 'updated';
    }

    /**
     * A booked row is only ever matched with a booked row. Rows that were
     * imported as reserved by earlier versions stay as they are, because the
     * keys they hold may belong to another transaction by now. They give up
     * those keys here, so the booked row can be stored next to them.
     */
    private function findExisting(User $user, ParsedBankTransaction $parsed): ?Transaction
    {
        $matches = $user->transactions()
            ->whereNull('parent_id')
            ->where(function ($query) use ($parsed): void {
                $query->where('external_id', $parsed->externalId)
                    ->orWhereIn('dedupe_key', $parsed->lookupKeys);
            })
            ->orderBy('id')
            ->get();

        [$reserved, $booked] = $matches->partition(
            fn (Transaction $transaction): bool => $transaction->booking_status === BookingStatus::Pending,
        );

        foreach ($reserved as $transaction) {
            $transaction->forceFill(['external_id' => null, 'dedupe_key' => null])->save();
        }

        return $booked->first();
    }

    /**
     * @param  list<string>  $ownAccountNumbers
     * @return array<string, mixed>
     */
    private function attributes(User $user, BankAccount $account, ParsedBankTransaction $parsed, array $ownAccountNumbers): array
    {
        $defaults = ($this->classify)($user, $account, $parsed, $ownAccountNumbers);

        return [
            'external_id' => $parsed->externalId,
            'bank_account_id' => $account->id,
            'booking_status' => BookingStatus::Booked,
            'dedupe_key' => $parsed->dedupeKey,
            'booked_on' => $parsed->bookedOn->toDateString(),
            'amount' => $parsed->absoluteAmount,
            'payee' => $parsed->payee,
            'note' => $parsed->note,
            'category' => $defaults->category,
            'needs_review' => $defaults->needsReview,
            'exclude_from_budget' => $defaults->excludeFromBudget,
            'payment_method' => $account->payment_method,
        ];
    }

    private function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
