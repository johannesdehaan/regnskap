<?php

use App\Enums\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Oversikt')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Transaction::class);
    }

    /**
     * @return Collection<int, array{name: string, budget: float, spent: float, leftover: float}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $month = now();
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        $spend = Auth::user()->transactions()
            ->excludingSplitParents()
            ->whereNotNull('category')
            ->whereDate('booked_on', '>=', $start)
            ->whereDate('booked_on', '<=', $end)
            ->get()
            ->groupBy(fn (Transaction $transaction): string => $transaction->category->value)
            ->map(fn (Collection $group): float => (float) $group->sum('amount'));

        $seen = [];
        $rows = collect();

        foreach (Auth::user()->budgetLines()->orderBy('name')->get() as $line) {
            $category = $line->mappedCategory();
            $spent = $category instanceof Category
                ? (float) ($spend[$category->value] ?? 0)
                : 0.0;
            $budget = (float) $line->monthly_nok;

            $rows->push([
                'name' => $line->name,
                'budget' => $budget,
                'spent' => $spent,
                'leftover' => $budget - $spent,
            ]);

            if ($category instanceof Category) {
                $seen[] = $category->value;
            }
        }

        foreach ($spend as $name => $amount) {
            if (in_array($name, $seen, true)) {
                continue;
            }

            $rows->push([
                'name' => $name,
                'budget' => 0.0,
                'spent' => (float) $amount,
                'leftover' => 0.0 - (float) $amount,
            ]);
        }

        return $rows;
    }

    #[Computed]
    public function totalBudget(): float
    {
        return (float) $this->rows->sum('budget');
    }

    #[Computed]
    public function totalSpent(): float
    {
        return (float) $this->rows->sum('spent');
    }

    #[Computed]
    public function totalLeftover(): float
    {
        return $this->totalBudget - $this->totalSpent;
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <div class="space-y-1">
        <flux:heading size="xl">{{ __('Oversikt') }}</flux:heading>
        <flux:text>{{ now()->translatedFormat('F Y') }}</flux:text>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:text class="text-xs">{{ __('Budsjett') }}</flux:text>
            <p class="text-lg font-semibold tabular-nums">{{ number_format($this->totalBudget, 0, ',', ' ') }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:text class="text-xs">{{ __('Brukt') }}</flux:text>
            <p class="text-lg font-semibold tabular-nums">{{ number_format($this->totalSpent, 0, ',', ' ') }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <flux:text class="text-xs">{{ __('Igjen') }}</flux:text>
            <p class="text-lg font-semibold tabular-nums">{{ number_format($this->totalLeftover, 0, ',', ' ') }}</p>
        </div>
    </div>

    <div class="space-y-3">
        @forelse ($this->rows as $row)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-baseline justify-between gap-3">
                    <flux:heading size="sm">{{ $row['name'] }}</flux:heading>
                    <p class="text-sm tabular-nums {{ $row['leftover'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ number_format($row['leftover'], 0, ',', ' ') }} {{ __('igjen') }}
                    </p>
                </div>
                <flux:text class="mt-1 text-sm tabular-nums">
                    {{ number_format($row['spent'], 0, ',', ' ') }}
                    /
                    {{ number_format($row['budget'], 0, ',', ' ') }} kr
                </flux:text>
            </div>
        @empty
            <flux:text>{{ __('Ingen budsjettposter eller kategoriserte utgifter denne måneden.') }}</flux:text>
        @endforelse
    </div>
</div>