<?php

namespace App\Actions;

use App\Enums\Category;
use App\Enums\Subcategory;
use App\Models\FoodBudgetAddition;
use App\Models\Transaction;
use App\Models\User;
use App\Support\NonFoodMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildFoodBudget
{
    public function __construct(private NonFoodMovement $movements) {}

    /**
     * The limit for a day is the base amount plus every addition day that is
     * inside that day's window. An addition day leaves the window on the same
     * day as the spending from that date.
     *
     * Spending is transactions only: rows the bank has booked and rows added
     * by hand or from a CSV. What the bank has reserved is reported apart, as
     * pending_total and remaining_today_including_pending, and moves no
     * other number.
     *
     * @return array{
     *     timezone: string,
     *     today: string,
     *     amount: string,
     *     additions_total: string,
     *     effective_amount: string,
     *     window_days: int,
     *     spent: string,
     *     remaining_today: string,
     *     over_limit: bool,
     *     pending_review: string,
     *     pending_total: string,
     *     remaining_today_including_pending: string,
     *     additions: list<array{id: int, label: string, starts_on: string, ends_on: string, amount_per_day: string, days_in_window: int, amount_in_window: string}>,
     *     days: list<array{date: string, spent: string, pending_review: string, addition: string}>,
     *     rolloff: list<array{date: string, drops: string, frees: string, additions_total: string, effective_amount: string, remaining_if_no_new_spending: string}>
     * }
     */
    public function __invoke(User $user, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now('Europe/Oslo'))->timezone('Europe/Oslo')->startOfDay();
        $window = max(1, (int) ($user->food_budget_window_days ?: 7));
        $baseCents = $this->cents((string) ($user->food_budget_amount ?: '1400.00'));
        $start = $today->subDays($window - 1);

        $transactions = $user->transactions()
            ->excludingSplitParents()
            ->excludingReserved()
            ->whereDate('booked_on', '>=', $start->toDateString())
            ->whereDate('booked_on', '<=', $today->toDateString())
            ->get();

        $reservedCents = 0;

        $reserved = $user->pendingBankTransactions()
            ->foodBetween($start->toDateString(), $today->toDateString())
            ->get();

        foreach ($reserved as $row) {
            $reservedCents += abs($this->cents((string) $row->amount));
        }

        /** @var Collection<string, Collection<int, Transaction>> $byDate */
        $byDate = $transactions->groupBy(
            fn (Transaction $transaction): string => $transaction->booked_on->toDateString(),
        );

        $additions = $this->additions($user, $start, $today->addDays($window));

        $days = [];
        $spentCents = 0;
        $pendingCents = 0;
        $additionsCents = 0;
        $spentByDate = [];
        $additionByDate = [];

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

            $dayAddition = $this->additionOn($additions, $date);

            $spentByDate[$date] = $daySpent;
            $additionByDate[$date] = $dayAddition;
            $spentCents += $daySpent;
            $pendingCents += $dayPending;
            $additionsCents += $dayAddition;
            $days[] = [
                'date' => $date,
                'spent' => $this->fromCents($daySpent),
                'pending_review' => $this->fromCents($dayPending),
                'addition' => $this->fromCents($dayAddition),
            ];
        }

        $effectiveCents = $baseCents + $additionsCents;
        $remainingCents = $effectiveCents - $spentCents;
        $rolloff = [];
        $freed = 0;
        $windowAdditions = $additionsCents;

        for ($step = 1; $step <= $window; $step++) {
            $date = $today->addDays($step)->toDateString();
            $drops = $today->subDays($window - $step)->toDateString();
            $frees = $spentByDate[$drops] ?? 0;
            $freed += $frees;
            $windowAdditions += $this->additionOn($additions, $date) - ($additionByDate[$drops] ?? 0);
            $rolloff[] = [
                'date' => $date,
                'drops' => $drops,
                'frees' => $this->fromCents($frees),
                'additions_total' => $this->fromCents($windowAdditions),
                'effective_amount' => $this->fromCents($baseCents + $windowAdditions),
                'remaining_if_no_new_spending' => $this->fromCents($baseCents + $windowAdditions - $spentCents + $freed),
            ];
        }

        return [
            'timezone' => 'Europe/Oslo',
            'today' => $today->toDateString(),
            'amount' => $this->fromCents($baseCents),
            'additions_total' => $this->fromCents($additionsCents),
            'effective_amount' => $this->fromCents($effectiveCents),
            'window_days' => $window,
            'spent' => $this->fromCents($spentCents),
            'remaining_today' => $this->fromCents($remainingCents),
            'over_limit' => $remainingCents < 0,
            'pending_review' => $this->fromCents($pendingCents),
            'pending_total' => $this->fromCents($reservedCents),
            'remaining_today_including_pending' => $this->fromCents($remainingCents - $reservedCents),
            'additions' => $this->additionBreakdown($additions, array_keys($additionByDate)),
            'days' => $days,
            'rolloff' => $rolloff,
        ];
    }

    /**
     * Additions that touch any day from the start of today's window to the
     * last roll-off date. Dates are compared as Y-m-d strings, so a day is
     * never counted twice or skipped when the clock changes.
     *
     * @return list<array{id: int, label: string, starts_on: string, ends_on: string, cents: int}>
     */
    private function additions(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values($user->foodBudgetAdditions()
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(fn (FoodBudgetAddition $addition): array => [
                'id' => $addition->id,
                'label' => $addition->label,
                'starts_on' => $addition->starts_on->toDateString(),
                'ends_on' => $addition->ends_on->toDateString(),
                'cents' => $this->cents((string) $addition->amount_per_day),
            ])
            ->all());
    }

    /**
     * @param  list<array{id: int, label: string, starts_on: string, ends_on: string, cents: int}>  $additions
     */
    private function additionOn(array $additions, string $date): int
    {
        $cents = 0;

        foreach ($additions as $addition) {
            if ($addition['starts_on'] <= $date && $date <= $addition['ends_on']) {
                $cents += $addition['cents'];
            }
        }

        return $cents;
    }

    /**
     * @param  list<array{id: int, label: string, starts_on: string, ends_on: string, cents: int}>  $additions
     * @param  list<string>  $windowDates
     * @return list<array{id: int, label: string, starts_on: string, ends_on: string, amount_per_day: string, days_in_window: int, amount_in_window: string}>
     */
    private function additionBreakdown(array $additions, array $windowDates): array
    {
        $breakdown = [];

        foreach ($additions as $addition) {
            $daysInWindow = count(array_filter(
                $windowDates,
                fn (string $date): bool => $addition['starts_on'] <= $date && $date <= $addition['ends_on'],
            ));

            if ($daysInWindow === 0) {
                continue;
            }

            $breakdown[] = [
                'id' => $addition['id'],
                'label' => $addition['label'],
                'starts_on' => $addition['starts_on'],
                'ends_on' => $addition['ends_on'],
                'amount_per_day' => $this->fromCents($addition['cents']),
                'days_in_window' => $daysInWindow,
                'amount_in_window' => $this->fromCents($daysInWindow * $addition['cents']),
            ];
        }

        return $breakdown;
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
