<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sparebank1\ParsedBankTransaction;
use App\Support\BankMovement;
use App\Support\FoodText;
use Illuminate\Database\UniqueConstraintViolationException;

class RecordSparebank1Transaction
{
    public function __construct(
        private ClassifyFoodPurchase $classify,
        private BankMovement $movement,
    ) {}

    /**
     * @param  list<string>  $ownAccountNumbers
     * @return 'imported'|'updated'|'skipped'
     */
    public function __invoke(User $user, BankAccount $account, ParsedBankTransaction $parsed, array $ownAccountNumbers): string
    {
        $existing = $this->findExisting($user, $account, $parsed);

        if ($existing instanceof Transaction) {
            return $this->update($user, $account, $existing, $parsed, $ownAccountNumbers);
        }

        try {
            $user->transactions()->create($this->attributes($user, $account, $parsed, $ownAccountNumbers));
        } catch (UniqueConstraintViolationException) {
            return 'skipped';
        }

        return 'imported';
    }

    /**
     * @param  list<string>  $ownAccountNumbers
     * @return 'updated'|'skipped'
     */
    private function update(
        User $user,
        BankAccount $account,
        Transaction $existing,
        ParsedBankTransaction $parsed,
        array $ownAccountNumbers,
    ): string {
        if ($existing->booking_status === BookingStatus::Booked && $parsed->bookingStatus === BookingStatus::Booked && $existing->external_id === $parsed->externalId) {
            return 'skipped';
        }

        $incoming = $this->attributes($user, $account, $parsed, $ownAccountNumbers);

        $existing->fill([
            'external_id' => $parsed->externalId,
            'dedupe_key' => $parsed->dedupeKey,
            'booking_status' => $parsed->bookingStatus,
            'bank_account_id' => $account->id,
            'booked_on' => $parsed->bookedOn->toDateString(),
            'amount' => $parsed->absoluteAmount,
        ]);

        if ($incoming['exclude_from_budget'] === true) {
            $existing->fill([
                'category' => null,
                'needs_review' => false,
                'exclude_from_budget' => true,
                'payee' => $incoming['payee'],
                'note' => $incoming['note'],
                'payment_method' => $incoming['payment_method'],
            ]);
        } elseif ($existing->category === null) {
            $existing->fill([
                'category' => $incoming['category'],
                'needs_review' => $incoming['needs_review'],
                'exclude_from_budget' => false,
                'payee' => $incoming['payee'],
                'note' => $incoming['note'],
                'payment_method' => $incoming['payment_method'],
            ]);
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

    private function findExisting(User $user, BankAccount $account, ParsedBankTransaction $parsed): ?Transaction
    {
        $match = $user->transactions()
            ->where(function ($query) use ($parsed): void {
                $query->where('external_id', $parsed->externalId)
                    ->orWhereIn('dedupe_key', $parsed->lookupKeys);
            })
            ->first();

        if ($match instanceof Transaction || $parsed->bookingStatus !== BookingStatus::Booked) {
            return $match;
        }

        $candidates = $user->transactions()
            ->where('bank_account_id', $account->id)
            ->where('booking_status', BookingStatus::Pending->value)
            ->where('amount', $parsed->absoluteAmount)
            ->whereDate('booked_on', '>=', $parsed->bookedOn->subDays(10)->toDateString())
            ->whereDate('booked_on', '<=', $parsed->bookedOn->addDays(2)->toDateString())
            ->get()
            ->filter(fn (Transaction $transaction): bool => $this->similar($transaction->payee, $parsed->payee)
                || $this->similar($transaction->note ?? '', $parsed->payee)
                || $this->similar($transaction->payee, $parsed->note ?? ''));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  list<string>  $ownAccountNumbers
     * @return array<string, mixed>
     */
    private function attributes(User $user, BankAccount $account, ParsedBankTransaction $parsed, array $ownAccountNumbers): array
    {
        $classification = ($this->classify)($user, $parsed->payee, $parsed->note);
        $category = $classification->category;
        $needsReview = $classification->needsReview;
        $exclude = false;

        if ($this->movement->isNonConsumption(
            $parsed->payee,
            $parsed->note,
            $parsed->typeText,
            $parsed->remoteAccountNumber,
            $account->account_type,
            $ownAccountNumbers,
        )) {
            $category = null;
            $needsReview = false;
            $exclude = true;
        }

        return [
            'external_id' => $parsed->externalId,
            'bank_account_id' => $account->id,
            'booking_status' => $parsed->bookingStatus,
            'dedupe_key' => $parsed->dedupeKey,
            'booked_on' => $parsed->bookedOn->toDateString(),
            'amount' => $parsed->absoluteAmount,
            'payee' => $parsed->payee,
            'note' => $parsed->note,
            'category' => $category,
            'needs_review' => $needsReview,
            'exclude_from_budget' => $exclude,
            'payment_method' => $account->payment_method,
        ];
    }

    private function similar(string $left, string $right): bool
    {
        $left = FoodText::normalize($left);
        $right = FoodText::normalize($right);

        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right || str_contains($left, $right) || str_contains($right, $left);
    }
}
