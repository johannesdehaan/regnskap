<?php

use App\Enums\HoldingType;
use App\Models\Holding;
use App\Models\HoldingPriceSchedule;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Investeringer')] class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $value = '';

    public string $price = '';

    public string $quantity = '';

    public string $ticker = '';

    public string $isin = '';

    public string $type = 'portefolje';

    /**
     * The times in the price schedule, as HH:MM.
     *
     * @var list<string>
     */
    public array $times = [];

    /**
     * The weekdays in the price schedule, as the strings the checkboxes hold.
     *
     * @var list<string>
     */
    public array $weekdays = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Holding::class);
        $this->fillSchedule();
    }

    #[Computed]
    public function schedule(): HoldingPriceSchedule
    {
        return HoldingPriceSchedule::for(Auth::user());
    }

    /**
     * @return Collection<int, Holding>
     */
    #[Computed]
    public function holdings(): Collection
    {
        return Auth::user()->holdings()->orderBy('name')->get();
    }

    #[Computed]
    public function totalValue(): float
    {
        return (float) $this->holdings->sum('value');
    }

    public function edit(int $id): void
    {
        $holding = Holding::query()->findOrFail($id);
        $this->authorize('update', $holding);

        $this->editingId = $holding->id;
        $this->name = $holding->name;
        $this->value = (string) $holding->value;
        $this->price = $holding->price !== null ? (string) $holding->price : '';
        $this->quantity = $holding->quantity !== null ? (string) $holding->quantity : '';
        $this->ticker = $holding->ticker ?? '';
        $this->isin = $holding->isin ?? '';
        $this->type = $holding->type->value;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->type = HoldingType::Portefolje->value;
        $this->reset('name', 'value', 'price', 'quantity', 'ticker', 'isin');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'ticker' => ['nullable', 'string', 'max:'.Holding::TICKER_MAX_LENGTH, 'regex:'.Holding::TICKER_PATTERN],
            'isin' => ['nullable', 'string', 'regex:'.Holding::ISIN_PATTERN],
            'type' => ['required', Rule::enum(HoldingType::class)],
        ]);

        $payload = [
            'name' => $validated['name'],
            'value' => number_format((float) $validated['value'], 2, '.', ''),
            'price' => $validated['price'] === null || $validated['price'] === ''
                ? null
                : number_format((float) $validated['price'], 4, '.', ''),
            'quantity' => $validated['quantity'] === null || $validated['quantity'] === ''
                ? null
                : number_format((float) $validated['quantity'], 6, '.', ''),
            'ticker' => Holding::code($validated['ticker']),
            'isin' => Holding::code($validated['isin']),
            'type' => HoldingType::from($validated['type']),
        ];

        if ($this->editingId !== null) {
            $holding = Holding::query()->findOrFail($this->editingId);
            $this->authorize('update', $holding);
            $holding->update($payload);
        } else {
            $this->authorize('create', Holding::class);
            Auth::user()->holdings()->create($payload);
        }

        Flux::toast(variant: 'success', text: __('Lagret.'));
        $this->cancel();
        unset($this->holdings, $this->totalValue);
    }

    public function delete(int $id): void
    {
        $holding = Holding::query()->findOrFail($id);
        $this->authorize('delete', $holding);
        $holding->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }

        Flux::toast(variant: 'success', text: __('Slettet.'));
        unset($this->holdings, $this->totalValue);
    }

    public function addTime(): void
    {
        if (count($this->times) < HoldingPriceSchedule::MAX_TIMES) {
            $this->times[] = '';
        }
    }

    public function removeTime(int $index): void
    {
        unset($this->times[$index]);
        $this->times = array_values($this->times);
    }

    public function saveSchedule(): void
    {
        $this->authorize('viewAny', Holding::class);

        // A row that was added and left empty is not a time.
        $this->times = array_values(array_filter($this->times, fn (mixed $time): bool => $time !== '' && $time !== null));

        $validated = $this->validate(HoldingPriceSchedule::rules(), HoldingPriceSchedule::messages());

        $this->schedule->reschedule($validated['times'], $validated['weekdays']);
        $this->fillSchedule();

        Flux::toast(variant: 'success', text: __('Lagret.'));
    }

    private function fillSchedule(): void
    {
        $this->times = $this->schedule->times;
        $this->weekdays = array_map(strval(...), $this->schedule->weekdays);
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <div class="flex items-baseline justify-between gap-4">
        <flux:heading size="xl">{{ __('Investeringer') }}</flux:heading>
        <p class="font-semibold tabular-nums">{{ number_format($this->totalValue, 0, ',', ' ') }} kr</p>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">{{ $editingId ? __('Rediger') : __('Ny beholdning') }}</flux:heading>
        <flux:input wire:model="name" :label="__('Navn')" required />
        <flux:select wire:model="type" :label="__('Type')" required>
            @foreach (\App\Enums\HoldingType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model="value" type="number" step="0.01" min="0" :label="__('Verdi')" required />
        <div class="grid grid-cols-2 gap-3">
            <flux:input wire:model="price" type="number" step="0.0001" min="0" :label="__('Kurs')" />
            <flux:input wire:model="quantity" type="number" step="0.000001" min="0" :label="__('Antall')" />
        </div>
        <flux:input
            wire:model="ticker"
            :label="__('Ticker')"
            :description="__('Yahoo-format, for eksempel EQNR.OL. Med ticker hentes kursen automatisk på tidspunktene nederst på siden, og verdien blir kurs ganger antall.')"
            :maxlength="\App\Models\Holding::TICKER_MAX_LENGTH"
            autocapitalize="characters"
            autocomplete="off"
            spellcheck="false"
        />
        <flux:input wire:model="isin" :label="__('ISIN')" maxlength="12" autocapitalize="characters" autocomplete="off" spellcheck="false" />
        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
            @if ($editingId)
                <flux:button type="button" variant="subtle" wire:click="cancel">{{ __('Avbryt') }}</flux:button>
            @endif
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($this->holdings as $holding)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-baseline justify-between gap-3">
                    <div>
                        <flux:heading size="sm">{{ $holding->name }}</flux:heading>
                        <flux:text class="text-sm">
                            {{ $holding->type->label() }}
                            @if ($holding->ticker)
                                · <span data-test="holding-ticker">{{ $holding->ticker }}</span>
                            @endif
                        </flux:text>
                        @if ($holding->price !== null && $holding->price_updated_at)
                            <flux:text class="text-sm" data-test="holding-price-updated">
                                {{-- Two to four decimals: trailing zeros beyond the second are dropped. --}}
                                {{ __('Kurs') }} {{ preg_replace('/0{1,2}$/', '', number_format((float) $holding->price, 4, ',', ' ')) }} kr
                                · {{ __('oppdatert') }} {{ $holding->price_updated_at->timezone('Europe/Oslo')->format('d.m.Y H:i') }}
                            </flux:text>
                        @elseif ($holding->ticker)
                            <flux:text class="text-sm" data-test="holding-price-updated">{{ __('Kursen er ikke hentet ennå.') }}</flux:text>
                        @endif
                    </div>
                    <p class="font-semibold tabular-nums">{{ number_format((float) $holding->value, 0, ',', ' ') }} kr</p>
                </div>
                <div class="mt-3 flex gap-3">
                    <flux:button size="sm" variant="subtle" wire:click="edit({{ $holding->id }})">{{ __('Rediger') }}</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="delete({{ $holding->id }})" wire:confirm="{{ __('Slette denne?') }}">{{ __('Slett') }}</flux:button>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Ingen beholdninger ennå.') }}</flux:text>
        @endforelse
    </div>

    <form wire:submit="saveSchedule" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="price-schedule">
        <div class="space-y-1">
            <flux:heading size="sm">{{ __('Automatisk kursoppdatering') }}</flux:heading>
            <flux:text class="text-sm">
                {{ __('Kursene hentes på disse tidspunktene, norsk tid, for beholdninger med ticker. Sover appen da, hentes de når den våkner.') }}
            </flux:text>
        </div>

        <flux:checkbox.group wire:model="weekdays" :label="__('Ukedager')" class="flex flex-wrap gap-x-5">
            @foreach ([1 => 'Man', 2 => 'Tir', 3 => 'Ons', 4 => 'Tor', 5 => 'Fre', 6 => 'Lør', 7 => 'Søn'] as $number => $label)
                <flux:checkbox value="{{ $number }}" :label="__($label)" />
            @endforeach
        </flux:checkbox.group>

        <div class="space-y-3">
            <flux:label>{{ __('Klokkeslett') }}</flux:label>
            @foreach ($times as $index => $time)
                <div wire:key="price-schedule-time-{{ $index }}">
                    <div class="flex items-center gap-3">
                        <flux:input
                            wire:model="times.{{ $index }}"
                            type="time"
                            class="max-w-36"
                            :aria-label="__('Klokkeslett').' '.($index + 1)"
                        />
                        <flux:button type="button" size="sm" variant="subtle" wire:click="removeTime({{ $index }})">{{ __('Fjern') }}</flux:button>
                    </div>
                    <flux:error name="times.{{ $index }}" />
                </div>
            @endforeach
            <flux:error name="times" :deep="false" />
            @if (count($times) < \App\Models\HoldingPriceSchedule::MAX_TIMES)
                <flux:button type="button" size="sm" variant="subtle" wire:click="addTime">{{ __('Legg til klokkeslett') }}</flux:button>
            @endif
        </div>

        <flux:button type="submit" variant="primary">{{ __('Lagre tidspunkter') }}</flux:button>

        @php
            $nextRun = $this->schedule->nextTime(now());
            $lastRun = $this->schedule->last_run_at;
        @endphp
        <flux:text class="text-sm" data-test="price-schedule-status">
            @if ($nextRun)
                {{ __('Neste') }}: {{ $nextRun->locale('nb')->isoFormat('dddd DD.MM.YYYY [kl.] HH:mm') }}.
            @endif
            @if ($lastRun)
                {{ __('Forrige') }}: {{ $lastRun->timezone('Europe/Oslo')->locale('nb')->isoFormat('dddd DD.MM.YYYY [kl.] HH:mm') }}.
            @else
                {{ __('Planen har ikke kjørt ennå.') }}
            @endif
        </flux:text>
    </form>
</div>
