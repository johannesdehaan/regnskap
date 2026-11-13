<?php

namespace App\Actions;

use App\Enums\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildOverview
{
    /**
     * Same numbers as the Oversikt page: budget envelopes vs categorized spend.
     *
     * @return array{month: string, budget: float, spent: float, leftover: float, rows: list<array{name: string, budget: float, spent: float, leftover: float}>}
     */
    public function __invoke(User $user, ?string $month = null): array
    {
        $date = $this->monthDate($month);
        $start = $date->startOfMonth()->toDateString();
        $end = $date->copy()->endOfMonth()->toDateString();

        $spend = $user->transactions()
            ->excludingSplitParents()
            ->whereNotNull('category')
            ->whereDate('booked_on', '>=', $start)
            ->whereDate('booked_on', '<=', $end)
            ->get()
            ->groupBy(fn (Transaction $transaction): string => $transaction->category->value)
            ->map(fn (Collection $group): float => (float) $group->sum('amount'));

        $seen = [];
        $rows = [];

        foreach ($user->budgetLines()->orderBy('name')->get() as $line) {
            $category = $line->mappedCategory();
            $spent = $category instanceof Category
                ? (float) ($spend[$category->value] ?? 0)
                : 0.0;
            $budget = (float) $line->monthly_nok;

            $rows[] = [
                'name' => $line->name,
                'budget' => $budget,
                'spent' => $spent,
                'leftover' => $budget - $spent,
            ];

            if ($category instanceof Category) {
                $seen[] = $category->value;
            }
        }

        foreach ($spend as $name => $amount) {
            if (in_array($name, $seen, true)) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'budget' => 0.0,
                'spent' => (float) $amount,
                'leftover' => 0.0 - (float) $amount,
            ];
        }

        $budget = (float) collect($rows)->sum('budget');
        $spent = (float) collect($rows)->sum('spent');

        return [
            'month' => $date->format('Y-m'),
            'budget' => $budget,
            'spent' => $spent,
            'leftover' => $budget - $spent,
            'rows' => $rows,
        ];
    }

    private function monthDate(?string $month): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::parse($month.'-01')->startOfMonth();
        }

        return now()->startOfMonth();
    }
}
