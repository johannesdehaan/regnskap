<?php

use App\Enums\Category;
use App\Enums\FigureSection;
use App\Models\MonthlyFigure;
use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Formue')] class extends Component
{
    #[Url]
    public string $month = '';

    public ?int $editingId = null;

    public string $section = 'formue';

    public string $item = '';

    public string $amount = '';

    public string $note = '';

    public function mount(): void
    {
        $this->authorize('viewAny', MonthlyFigure::class);

        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    public function monthDate(): Carbon
    {
        $value = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1
            ? $this->month.'-01'
            : now()->toDateString();

        return Carbon::parse($value)->startOfMonth();
    }

    /**
     * @return Collection<int, MonthlyFigure>
     */
    #[Computed]
    public function figures(): Collection
    {
        return Auth::user()->monthlyFigures()
            ->whereDate('month', $this->monthDate()->toDateString())
            ->orderBy('section')
            ->orderBy('item')
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, MonthlyFigure>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return $this->figures->groupBy(fn (MonthlyFigure $figure): string => $figure->section->value);
    }

    /**
     * @return Collection<string, float>
     */
    #[Computed]
    public function computedResultat(): Collection
    {
        $start = $this->monthDate()->toDateString();
        $end = $this->monthDate()->endOfMonth()->toDateString();

        return Auth::user()->transactions()
            ->excludingSplitParents()
            ->whereNotNull('category')
            ->whereDate('booked_on', '>=', $start)
            ->whereDate('booked_on', '<=', $end)
            ->get()
            ->groupBy(fn (Transaction $transaction): string => $transaction->category->value)
            ->map(fn (Collection $group): float => (float) $group->sum('amount'));
    }

    public function edit(int $id): void
    {
        $figure = MonthlyFigure::query()->findOrFail($id);
        $this->authorize('update', $figure);

        $this->editingId = $figure->id;
        $this->section = $figure->section->value;
        $this->item = $figure->item;
        $this->amount = (string) $figure->amount;
        $this->note = (string) ($figure->note ?? '');
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->section = FigureSection::Formue->value;
        $this->reset('item', 'amount', 'note');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'section' => ['required', Rule::enum(FigureSection::class)],
            'item' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
            'note' => ['nullable', 'string'],
        ]);

        $payload = [
            'month' => $this->monthDate()->toDateString(),
            'section' => FigureSection::from($validated['section']),
            'item' => $validated['item'],
            'amount' => number_format((float) $validated['amount'], 2, '.', ''),
            'note' => $validated['note'] ?: null,
        ];

        if ($this->editingId !== null) {
            $figure = MonthlyFigure::query()->findOrFail($this->editingId);
            $this->authorize('update', $figure);
            $figure->update($payload);
        } else {
            $this->authorize('create', MonthlyFigure::class);
            Auth::user()->monthlyFigures()->updateOrCreate(
                [
                    'month' => $payload['month'],
                    'section' => $payload['section'],
                    'item' => $payload['item'],
                ],
                [
                    'amount' => $payload['amount'],
                    'note' => $payload['note'],
                ],
            );
        }

        Flux::toast(variant: 'success', text: __('Lagret.'));
        $this->cancel();
        unset($this->figures, $this->grouped);
    }

    public function delete(int $id): void
    {
        $figure = MonthlyFigure::query()->findOrFail($id);
        $this->authorize('delete', $figure);
        $figure->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }

        Flux::toast(variant: 'success', text: __('Slettet.'));
        unset($this->figures, $this->grouped);
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <flux:heading size="xl">{{ __('Formue') }}</flux:heading>
    <flux:input wire:model.live="month" type="month" :label="__('Måned')" />

    @if ($this->computedResultat->isNotEmpty())
        <div class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Resultat fra transaksjoner') }}</flux:heading>
            @foreach ($this->computedResultat as $name => $spent)
                <div class="flex justify-between gap-3 text-sm">
                    <span>{{ $name }}</span>
                    <span class="tabular-nums">{{ number_format($spent, 0, ',', ' ') }} kr</span>
                </div>
            @endforeach
        </div>
    @endif

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">{{ $editingId ? __('Rediger tall') : __('Nytt tall') }}</flux:heading>
        <flux:select wire:model="section" :label="__('Seksjon')" required>
            @foreach (\App\Enums\FigureSection::cases() as $section)
                <option value="{{ $section->value }}">{{ $section->label() }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model="item" :label="__('Post')" required />
        <flux:input wire:model="amount" type="number" step="0.01" :label="__('Beløp')" required />
        <flux:textarea wire:model="note" :label="__('Notat')" rows="2" />
        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
            @if ($editingId)
                <flux:button type="button" variant="subtle" wire:click="cancel">{{ __('Avbryt') }}</flux:button>
            @endif
        </div>
    </form>

    @forelse (\App\Enums\FigureSection::cases() as $section)
        @php($items = $this->grouped->get($section->value, collect()))
        @if ($items->isNotEmpty())
            <div class="space-y-2">
                <flux:heading size="sm">{{ $section->label() }}</flux:heading>
                @foreach ($items as $figure)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex items-baseline justify-between gap-3">
                            <span>{{ $figure->item }}</span>
                            <span class="font-semibold tabular-nums">{{ number_format((float) $figure->amount, 0, ',', ' ') }} kr</span>
                        </div>
                        @if ($figure->note)
                            <flux:text class="mt-1 text-sm">{{ $figure->note }}</flux:text>
                        @endif
                        <div class="mt-3 flex gap-3">
                            <flux:button size="sm" variant="subtle" wire:click="edit({{ $figure->id }})">{{ __('Rediger') }}</flux:button>
                            <flux:button size="sm" variant="subtle" wire:click="delete({{ $figure->id }})" wire:confirm="{{ __('Slette denne?') }}">{{ __('Slett') }}</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @empty
    @endforelse

    @if ($this->figures->isEmpty())
        <flux:text>{{ __('Ingen lagrede tall for denne måneden.') }}</flux:text>
    @endif
</div>
