<?php

namespace App\Actions;

use App\Enums\Category;
use App\Enums\Subcategory;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SplitTransactionLines
{
    /**
     * Replace the children of a bank transaction with receipt lines.
     * Amounts are compared in øre and must match the parent exactly.
     * Children stay free of bank identifiers so SpareBank 1 dedupe keeps
     * matching the parent row.
     *
     * @param  list<array{category: Category, subcategory: Subcategory|null, amount: string, note: string|null}>  $lines
     */
    public function replace(Transaction $parent, array $lines): Transaction
    {
        return DB::transaction(function () use ($parent, $lines): Transaction {
            $locked = $this->lock($parent);
            $normalized = $this->normalize($locked, $lines);

            $locked->children()->delete();

            foreach ($normalized as $line) {
                $locked->children()->create([
                    'user_id' => $locked->user_id,
                    'booked_on' => $locked->booked_on,
                    'amount' => $line['amount'],
                    'category' => $line['category'],
                    'subcategory' => $line['subcategory'],
                    'payee' => $locked->payee,
                    'payment_method' => $locked->payment_method,
                    'note' => $line['note'],
                    'needs_review' => false,
                    'exclude_from_budget' => false,
                ]);
            }

            $locked->needs_review = false;
            $locked->save();

            return $this->withChildren($locked);
        });
    }

    public function clear(Transaction $parent): Transaction
    {
        return DB::transaction(function () use ($parent): Transaction {
            $locked = $this->lock($parent);
            $locked->children()->delete();

            return $this->withChildren($locked);
        });
    }

    private function withChildren(Transaction $transaction): Transaction
    {
        $transaction->load('children');
        $transaction->setRelation('children', $transaction->children->sortBy('id')->values());

        return $transaction;
    }

    private function lock(Transaction $parent): Transaction
    {
        $locked = Transaction::query()->whereKey($parent->id)->lockForUpdate()->firstOrFail();

        if ($locked->parent_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => 'En deling kan bare settes på den opprinnelige transaksjonen.',
            ]);
        }

        return $locked;
    }

    /**
     * @param  list<array{category: Category, subcategory: Subcategory|null, amount: string, note: string|null}>  $lines
     * @return list<array{category: Category, subcategory: Subcategory|null, amount: string, note: string|null}>
     */
    private function normalize(Transaction $parent, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Delingen må ha minst én linje.',
            ]);
        }

        $sum = 0;
        $normalized = [];

        foreach ($lines as $index => $line) {
            $subcategory = $line['subcategory'];

            if ($subcategory instanceof Subcategory && $subcategory->category() !== $line['category']) {
                throw ValidationException::withMessages([
                    "lines.$index.subcategory" => 'Underkategorien hører ikke til kategorien.',
                ]);
            }

            $cents = $this->toCents($line['amount']);
            $sum += $cents;
            $normalized[] = [
                'category' => $line['category'],
                'subcategory' => $subcategory,
                'amount' => $this->fromCents($cents),
                'note' => $line['note'],
            ];
        }

        if ($sum !== $this->toCents((string) $parent->amount)) {
            throw ValidationException::withMessages([
                'lines' => 'Delbeløpene må summeres nøyaktig til transaksjonens beløp.',
            ]);
        }

        return $normalized;
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
