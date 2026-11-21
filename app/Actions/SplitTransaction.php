<?php

namespace App\Actions;

use App\Enums\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SplitTransaction
{
    /**
     * Split a parent into two categorized children whose amounts sum exactly
     * to the original. Either part may be negative, for a discount or a
     * redeemed store bonus, but neither may be zero. Existing children are
     * replaced. The parent stays, with needs_review cleared, so the inbox
     * moves on (see Transaction).
     */
    public function __invoke(Transaction $parent, string $firstAmount, Category $first, Category $second): void
    {
        $totalCents = $this->toCents((string) $parent->amount);
        $firstCents = $this->toCents($firstAmount);

        if ($totalCents <= 0 || $firstCents === 0 || $firstCents === $totalCents) {
            throw ValidationException::withMessages([
                'splitAmount' => 'Delbeløpet kan ikke være 0 eller lik totalsummen.',
            ]);
        }

        $secondCents = $totalCents - $firstCents;

        DB::transaction(function () use ($parent, $firstCents, $secondCents, $first, $second): void {
            $parent->children()->delete();

            $parent->children()->create([
                'user_id' => $parent->user_id,
                'booked_on' => $parent->booked_on,
                'amount' => $this->fromCents($firstCents),
                'category' => $first,
                'payee' => $parent->payee,
                'payment_method' => $parent->payment_method,
                'note' => $parent->note,
                'needs_review' => false,
                'exclude_from_budget' => false,
            ]);

            $parent->children()->create([
                'user_id' => $parent->user_id,
                'booked_on' => $parent->booked_on,
                'amount' => $this->fromCents($secondCents),
                'category' => $second,
                'payee' => $parent->payee,
                'payment_method' => $parent->payment_method,
                'note' => $parent->note,
                'needs_review' => false,
                'exclude_from_budget' => false,
            ]);

            $parent->needs_review = false;
            $parent->save();
        });
    }

    private function toCents(string $amount): int
    {
        $normalized = str_replace([' ', "\u{00A0}"], '', $amount);
        $normalized = str_replace(',', '.', $normalized);

        return (int) round(((float) $normalized) * 100);
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
