<?php

namespace App\Actions;

use App\Enums\Category;
use App\Enums\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use App\Support\NonFoodMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildFoodBudget
{
    public function __construct(private NonFoodMovement $movements) {}

    /**
     * @return array{
     *     timezone: string,
     *     today: string,
     *     amount: string,
     *     window_days: int,
     *     spent: string,
     *     remaining_today: string,
     *     over_limit: bool,
     *     pending_review: string,
     *     days: list<array{date: string, spent: string, pending_review: string}>,
     *     rolloff: list<array{date: string, drops: string, frees: string, remaining_if_no_new_spending: string}>
     * }
     */
    public function __invoke(User $user, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now('Europe/Oslo'))->timezone('Europe/Oslo')->startOfDay();
        $window = max(1, (int) ($user->food_budget_window_days ?: 7));
        $limitCents = $this->cents((string) ($user->food_budget_amount ?: '1400.00'));
        $start = $today->subDays($window - 1);

        $transactions = $user->transactions()
            ->excludingSplitParents()
            ->whereDate('booked_on', '>=', $start->toDateString())
            ->whereDate('booked_on', '<=', $today->toDateString())
            ->get();

        /** @var Collection<string, Collection<int, Transaction>> $byDate */
        $byDate = $transactions->groupBy(
            fn (Transaction $transaction): string => $transaction->booked_on->toDateString(),
        );

        $days = [];
        $spentCents = 0;
        $pendingCents = 0;
        $spentByDate = [];

        for ($offset = 0; $offset < $window; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $rows = $byDate->get($date, collect());
            $daySpent = 0;
            $dayPending = 0;

            foreach ($rows as $transaction) {
                if ($this->counts($user, $transaction)) {
                    $daySpent += $this->cents((string) $transaction->amount);
                } elseif ($this->isPending($user, $transaction)) {
                    $dayPending += $this->cents((string) $transaction->amount);
                }
            }

            $spentByDate[$date] = $daySpent;
            $spentCents += $daySpent;
            $pendingCents += $dayPending;
            $days[] = [
                'date' => $date,
                'spent' => $this->fromCents($daySpent),
                'pending_review' => $this->fromCents($dayPending),
            ];
        }

        $remainingCents = $limitCents - $spentCents;
        $rolloff = [];
        $freed = 0;

        for ($step = 1; $step <= $window; $step++) {
            $drops = $today->subDays($window - $step)->toDateString();
            $frees = $spentByDate[$drops] ?? 0;
            $freed += $frees;
            $rolloff[] = [
                'date' => $today->addDays($step)->toDateString(),
                'drops' => $drops,
                'frees' => $this->fromCents($frees),
                'remaining_if_no_new_spending' => $this->fromCents($remainingCents + $freed),
            ];
        }

        return [
            'timezone' => 'Europe/Oslo',
            'today' => $today->toDateString(),
            'amount' => $this->fromCents($limitCents),
            'window_days' => $window,
            'spent' => $this->fromCents($spentCents),
            'remaining_today' => $this->fromCents($remainingCents),
            'over_limit' => $remainingCents < 0,
            'pending_review' => $this->fromCents($pendingCents),
            'days' => $days,
            'rolloff' => $rolloff,
        ];
    }

    private function counts(User $user, Transaction $transaction): bool
    {
        if ($transaction->exclude_from_budget || $transaction->needs_review || ! $this->isFood($transaction)) {
            return false;
        }

        return ! $this->movements->isIgnored($user, $transaction->payee, $transaction->note);
    }

    private function isPending(User $user, Transaction $transaction): bool
    {
        if ($transaction->exclude_from_budget || ! $transaction->needs_review) {
            return false;
        }

        if ($transaction->subcategory instanceof Subcategory && ! $transaction->subcategory->countsAsFood()) {
            return false;
        }

        return ! $this->movements->isIgnored($user, $transaction->payee, $transaction->note);
    }

    private function isFood(Transaction $transaction): bool
    {
        if ($transaction->subcategory instanceof Subcategory && ! $transaction->subcategory->countsAsFood()) {
            return false;
        }

        $category = $transaction->category;

        return $category instanceof Category && $category->countsAsFood();
    }

    private function cents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';

        return $sign.number_format(abs($cents) / 100, 2, '.', '');
    }
}
