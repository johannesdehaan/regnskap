<?php

use App\Actions\BuildFoodBudget;
use App\Models\FoodBudgetAddition;
use App\Models\PendingBankTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Matbudsjett')] class extends Component
{
    private const ADDITION_FIELDS = ['additionLabel', 'additionStartsOn', 'additionEndsOn', 'additionAmountPerDay'];

    public string $amount = '';

    public int $windowDays = 7;

    public ?int $editingAdditionId = null;

    public string $additionLabel = '';

    public string $additionStartsOn = '';

    public string $additionEndsOn = '';

    public string $additionAmountPerDay = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Transaction::class);

        $user = Auth::user();
        $this->amount = (string) ($user->food_budget_amount ?: '1400.00');
        $this->windowDays = (int) ($user->food_budget_window_days ?: 7);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function status(): array
    {
        return app(BuildFoodBudget::class)(Auth::user());
    }

    /**
     * The reserved purchases behind pending_total.
     *
     * @return Collection<int, PendingBankTransaction>
     */
    #[Computed]
    public function reserved(): Collection
    {
        $today = CarbonImmutable::parse($this->status['today']);

        return Auth::user()->pendingBankTransactions()
            ->foodBetween($today->subDays($this->status['window_days'] - 1)->toDateString(), $today->toDateString())
            ->orderByDesc('booked_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Every addition, newest first. An addition is active while at least one
     * of its days is inside today's window.
     *
     * @return list<array{id: int, label: string, period: string, amount_per_day: string, state: string, days_in_window: int, amount_in_window: string}>
     */
    #[Computed]
    public function additions(): array
    {
        $inWindow = collect($this->status['additions'])->keyBy('id');
        $today = $this->status['today'];

        return Auth::user()->foodBudgetAdditions()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(function (FoodBudgetAddition $addition) use ($inWindow, $today): array {
                $row = $inWindow->get($addition->id);
                $startsOn = $addition->starts_on->toDateString();

                return [
                    'id' => $addition->id,
                    'label' => $addition->label,
                    'period' => $this->periodLabel($startsOn, $addition->ends_on->toDateString()),
                    'amount_per_day' => (string) $addition->amount_per_day,
                    'state' => match (true) {
                        $row !== null => 'active',
                        $startsOn > $today => 'upcoming',
                        default => 'ended',
                    },
                    'days_in_window' => $row['days_in_window'] ?? 0,
                    'amount_in_window' => $row['amount_in_window'] ?? '0.00',
                ];
            })
            ->values()
            ->all();
    }

    public function saveSettings(): void
    {
        $this->authorize('viewAny', Transaction::class);

        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'windowDays' => ['required', 'integer', 'min:1', 'max:62'],
        ]);

        Auth::user()->update([
            'food_budget_amount' => number_format((float) $validated['amount'], 2, '.', ''),
            'food_budget_window_days' => (int) $validated['windowDays'],
        ]);

        unset($this->status, $this->additions);

        Flux::toast(variant: 'success', text: __('Matbudsjettet er oppdatert.'));
    }

    public function saveAddition(): void
    {
        $validated = $this->validate([
            'additionLabel' => ['required', 'string', 'max:100'],
            'additionStartsOn' => ['required', 'date_format:Y-m-d'],
            'additionEndsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:additionStartsOn'],
            'additionAmountPerDay' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ], [
            'additionEndsOn.after_or_equal' => 'Sluttdatoen kan ikke være før startdatoen.',
        ]);

        $payload = [
            'label' => trim($validated['additionLabel']),
            'starts_on' => $validated['additionStartsOn'],
            'ends_on' => $validated['additionEndsOn'],
            'amount_per_day' => number_format((float) $validated['additionAmountPerDay'], 2, '.', ''),
        ];

        if ($this->editingAdditionId !== null) {
            $addition = FoodBudgetAddition::query()->findOrFail($this->editingAdditionId);
            $this->authorize('update', $addition);
            $addition->update($payload);
            Flux::toast(variant: 'success', text: __('Tillegget er oppdatert.'));
        } else {
            $this->authorize('create', FoodBudgetAddition::class);
            Auth::user()->foodBudgetAdditions()->create($payload);
            Flux::toast(variant: 'success', text: __('Tillegget er lagret.'));
        }

        $this->cancelAddition();
        unset($this->status, $this->additions);
    }

    public function editAddition(int $id): void
    {
        $addition = FoodBudgetAddition::query()->findOrFail($id);
        $this->authorize('update', $addition);

        $this->editingAdditionId = $addition->id;
        $this->additionLabel = $addition->label;
        $this->additionStartsOn = $addition->starts_on->toDateString();
        $this->additionEndsOn = $addition->ends_on->toDateString();
        $this->additionAmountPerDay = (string) $addition->amount_per_day;
        $this->resetValidation(self::ADDITION_FIELDS);
    }

    public function cancelAddition(): void
    {
        $this->editingAdditionId = null;
        $this->reset(self::ADDITION_FIELDS);
        $this->resetValidation(self::ADDITION_FIELDS);
    }

    public function deleteAddition(int $id): void
    {
        $addition = FoodBudgetAddition::query()->findOrFail($id);
        $this->authorize('delete', $addition);
        $addition->delete();

        if ($this->editingAdditionId === $id) {
            $this->cancelAddition();
        }

        unset($this->status, $this->additions);

        Flux::toast(variant: 'success', text: __('Tillegget er slettet.'));
    }

    public function kroner(string $amount): string
    {
        $value = (float) $amount;
        $decimals = abs($value - round($value)) < 0.001 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ');
    }

    public function dayLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('nb')->translatedFormat('D j. M');
    }

    public function periodLabel(string $from, string $to): string
    {
        $thisYear = substr($this->status['today'], 0, 4);
        $format = fn (string $date): string => CarbonImmutable::parse($date)->locale('nb')->translatedFormat(
            str_starts_with($date, $thisYear) ? 'j. M' : 'j. M Y',
        );

        return $from === $to ? $format($from) : $format($from).' – '.$format($to);
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <div class="space-y-1">
        <flux:heading size="xl">{{ __('Matbudsjett') }}</flux:heading>
        <flux:text>
            {{ __(':amount kr per :days dager, i norsk tid.', [
                'amount' => $this->kroner($this->status['amount']),
                'days' => $this->status['window_days'],
            ]) }}
            @if ($this->status['additions'] !== [])
                <span data-test="food-effective">
                    {{ __('Med :extra kr i tillegg er grensen :effective kr i dag.', [
                        'extra' => $this->kroner($this->status['additions_total']),
                        'effective' => $this->kroner($this->status['effective_amount']),
                    ]) }}
                </span>
            @endif
        </flux:text>
    </div>

    <x-food-budget-summary :status="$this->status" />

    <div class="space-y-2">
        <flux:heading size="sm">{{ __('Dag for dag') }}</flux:heading>
        <div class="space-y-2">
            @foreach ($this->status['days'] as $day)
                <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700" data-test="food-day">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm {{ $day['date'] === $this->status['today'] ? 'font-semibold' : '' }}">
                            {{ $this->dayLabel($day['date']) }}
                            @if ($day['date'] === $this->status['today'])
                                <span class="text-xs font-normal">{{ __('i dag') }}</span>
                            @endif
                        </p>
                        <p class="text-sm tabular-nums">
                            {{ $this->kroner($day['spent']) }} kr
                            @if ((float) $day['pending_review'] > 0)
                                <span class="text-amber-700 dark:text-amber-300">+ {{ $this->kroner($day['pending_review']) }} {{ __('usikkert') }}</span>
                            @endif
                        </p>
                    </div>
                    @if ((float) $day['addition'] > 0)
                        <p class="text-xs text-emerald-700 tabular-nums dark:text-emerald-300" data-test="food-day-addition">
                            {{ __('+ :amount kr i tillegg', ['amount' => $this->kroner($day['addition'])]) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <x-reserved-bank-rows :rows="$this->reserved" />

    @if ($this->status['rolloff'] !== [])
        <div class="space-y-2">
            <flux:heading size="sm">{{ __('Når gamle utgifter faller ut') }}</flux:heading>
            <flux:text class="text-sm">
                {{ __('Slik øker rommet hvis du ikke bruker mer. Beløpet som faller ut, er mat du allerede har brukt.') }}
            </flux:text>
            <div class="space-y-2">
                @foreach ($this->status['rolloff'] as $step)
                    <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700" data-test="food-rolloff">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm">{{ $this->dayLabel($step['date']) }}</p>
                            <p class="text-sm font-semibold tabular-nums">+ {{ $this->kroner($step['frees']) }} kr</p>
                        </div>
                        <flux:text class="text-xs">
                            {{ __('Utgiftene fra :from faller ut. Da kan du bruke :left kr.', [
                                'from' => $this->dayLabel($step['drops']),
                                'left' => $this->kroner($step['remaining_if_no_new_spending']),
                            ]) }}
                            @if ($step['effective_amount'] !== $this->status['effective_amount'])
                                {{ __('Grensen er da :limit kr.', ['limit' => $this->kroner($step['effective_amount'])]) }}
                            @endif
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="space-y-3">
        <div class="space-y-1">
            <flux:heading size="sm">{{ __('Tillegg') }}</flux:heading>
            <flux:text class="text-sm">
                {{ __('Ekstra budsjett per dag i en periode, for eksempel når du har besøk. Hver dag i perioden teller så lenge den er inne i vinduet, og faller ut på samme måte som utgiftene.') }}
            </flux:text>
        </div>

        @forelse ($this->additions as $addition)
            <div
                @class([
                    'rounded-xl border border-zinc-200 p-4 dark:border-zinc-700',
                    'opacity-60' => $addition['state'] === 'ended',
                ])
                wire:key="food-addition-{{ $addition['id'] }}"
                data-test="food-addition"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <flux:heading size="sm">{{ $addition['label'] }}</flux:heading>
                    <p class="text-sm font-semibold tabular-nums">
                        {{ __('+ :amount kr per dag', ['amount' => $this->kroner($addition['amount_per_day'])]) }}
                    </p>
                </div>
                <flux:text class="mt-1 text-sm">
                    {{ $addition['period'] }}
                    ·
                    @if ($addition['state'] === 'active')
                        {{ __(':amount kr i dagens grense', ['amount' => $this->kroner($addition['amount_in_window'])]) }}
                        ({{ trans_choice(':count dag i vinduet|:count dager i vinduet', $addition['days_in_window']) }})
                    @elseif ($addition['state'] === 'upcoming')
                        {{ __('Ikke startet') }}
                    @else
                        {{ __('Avsluttet') }}
                    @endif
                </flux:text>
                <div class="mt-3 flex gap-2">
                    <flux:button size="sm" variant="subtle" wire:click="editAddition({{ $addition['id'] }})">{{ __('Endre') }}</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="deleteAddition({{ $addition['id'] }})" wire:confirm="{{ __('Slette dette tillegget?') }}">{{ __('Slett') }}</flux:button>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Ingen tillegg ennå.') }}</flux:text>
        @endforelse

        <form wire:submit="saveAddition" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ $editingAdditionId ? __('Endre tillegg') : __('Nytt tillegg') }}</flux:heading>
            <flux:input wire:model="additionLabel" type="text" maxlength="100" :label="__('Navn')" :placeholder="__('Gjester på besøk')" required />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="additionStartsOn" type="date" :label="__('Fra og med')" required />
                <flux:input wire:model="additionEndsOn" type="date" :label="__('Til og med')" required />
            </div>
            <flux:input wire:model="additionAmountPerDay" type="number" step="0.01" min="0.01" :label="__('Beløp per dag (kr)')" required />
            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">{{ $editingAdditionId ? __('Lagre tillegg') : __('Legg til') }}</flux:button>
                @if ($editingAdditionId)
                    <flux:button type="button" variant="subtle" wire:click="cancelAddition">{{ __('Avbryt') }}</flux:button>
                @endif
            </div>
        </form>
    </div>

    <form wire:submit="saveSettings" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">{{ __('Innstillinger') }}</flux:heading>
        <flux:input wire:model="amount" type="number" step="0.01" min="0" :label="__('Beløp per periode (kr)')" required />
        <flux:input wire:model="windowDays" type="number" min="1" max="62" :label="__('Antall dager i vinduet')" required />
        <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
    </form>

    <flux:button :href="route('mat.rules')" wire:navigate variant="subtle">{{ __('Rediger gjenkjenningsregler') }}</flux:button>
</div>
