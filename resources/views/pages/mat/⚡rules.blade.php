<?php

use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use App\Models\FoodRule;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Matregler')] class extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    public string $pattern = '';

    public string $effect = 'count';

    public string $match_type = 'contains';

    public string $category = 'Dagligvarer';

    public int $priority = 110;

    public function mount(): void
    {
        $this->authorize('viewAny', FoodRule::class);
    }

    /**
     * @return Collection<int, FoodRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        $query = Auth::user()->foodRules()->orderByDesc('priority')->orderBy('pattern');

        if (trim($this->search) !== '') {
            $query->where('pattern', 'like', '%'.trim($this->search).'%');
        }

        return $query->get();
    }

    public function edit(int $id): void
    {
        $rule = $this->findOwn($id);
        $this->authorize('update', $rule);

        $this->editingId = $rule->id;
        $this->pattern = $rule->pattern;
        $this->effect = $rule->effect->value;
        $this->match_type = $rule->match_type->value;
        $this->category = $rule->category?->value ?? '';
        $this->priority = $rule->priority;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('create', FoodRule::class);

        $validated = $this->validate([
            'pattern' => ['required', 'string', 'max:255'],
            'effect' => ['required', Rule::enum(FoodRuleEffect::class)],
            'match_type' => ['required', Rule::enum(FoodRuleMatch::class)],
            'category' => ['nullable', Rule::enum(Category::class)],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $pattern = trim($validated['pattern']);
        $duplicate = Auth::user()->foodRules()
            ->where('pattern', $pattern)
            ->where('match_type', $validated['match_type'])
            ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'pattern' => 'Du har allerede en regel for dette mønsteret.',
            ]);
        }

        $payload = [
            'pattern' => $pattern,
            'effect' => FoodRuleEffect::from($validated['effect']),
            'match_type' => FoodRuleMatch::from($validated['match_type']),
            'category' => filled($validated['category'] ?? null) ? Category::from($validated['category']) : null,
            'priority' => (int) $validated['priority'],
        ];

        if ($this->editingId !== null) {
            $rule = $this->findOwn($this->editingId);
            $this->authorize('update', $rule);
            $rule->update($payload);
            Flux::toast(variant: 'success', text: __('Regelen er oppdatert.'));
        } else {
            Auth::user()->foodRules()->create($payload);
            Flux::toast(variant: 'success', text: __('Regelen er lagret.'));
        }

        $this->resetForm();
        unset($this->rules);
    }

    public function delete(int $id): void
    {
        $rule = $this->findOwn($id);
        $this->authorize('delete', $rule);
        $rule->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        unset($this->rules);
        Flux::toast(variant: 'success', text: __('Regelen er slettet.'));
    }

    public function findOwn(int $id): FoodRule
    {
        $rule = Auth::user()->foodRules()->findOrFail($id);
        $this->authorize('view', $rule);

        return $rule;
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->pattern = '';
        $this->effect = FoodRuleEffect::Count->value;
        $this->match_type = FoodRuleMatch::Contains->value;
        $this->category = Category::Dagligvarer->value;
        $this->priority = 110;
    }

    public function effectLabel(FoodRuleEffect $effect): string
    {
        return match ($effect) {
            FoodRuleEffect::Count => 'Teller som mat',
            FoodRuleEffect::Review => 'Må vurderes',
            FoodRuleEffect::Exclude => 'Ikke mat',
        };
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <div class="space-y-1">
        <flux:heading size="xl">{{ __('Matregler') }}</flux:heading>
        <flux:text>
            {{ __('Reglene matcher brukersted og melding, uten å skille på store og små bokstaver. Ekskludering vinner over brede treff. Brukersteder som begynner med Express er drivstoff og blir Bil.') }}
        </flux:text>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">{{ $editingId ? __('Endre regel') : __('Ny regel') }}</flux:heading>
        <flux:input wire:model="pattern" type="text" :label="__('Mønster')" required />
        <flux:select wire:model="match_type" :label="__('Treff')">
            <option value="contains">{{ __('Inneholder') }}</option>
            <option value="starts_with">{{ __('Begynner med') }}</option>
        </flux:select>
        <flux:select wire:model="effect" :label="__('Utfall')">
            <option value="count">{{ __('Teller som mat') }}</option>
            <option value="review">{{ __('Må vurderes') }}</option>
            <option value="exclude">{{ __('Ikke mat') }}</option>
        </flux:select>
        <flux:select wire:model="category" :label="__('Kategori')">
            <option value="">{{ __('Ingen') }}</option>
            @foreach (\App\Enums\Category::cases() as $categoryOption)
                <option value="{{ $categoryOption->value }}">{{ $categoryOption->value }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model="priority" type="number" min="0" max="1000" :label="__('Prioritet')" required />
        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
            @if ($editingId)
                <flux:button type="button" variant="subtle" wire:click="cancel">{{ __('Avbryt') }}</flux:button>
            @endif
        </div>
    </form>

    <flux:input wire:model.live.debounce.300ms="search" type="search" :label="__('Søk i regler')" :placeholder="__('Rema, Express, Obs')" />

    <div class="space-y-3">
        @forelse ($this->rules as $rule)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="food-rule-{{ $rule->id }}">
                <div class="flex items-baseline justify-between gap-3">
                    <flux:heading size="sm">{{ $rule->pattern }}</flux:heading>
                    <p class="text-xs tabular-nums">{{ $rule->priority }}</p>
                </div>
                <flux:text class="mt-1 text-sm">
                    {{ $rule->match_type === \App\Enums\FoodRuleMatch::StartsWith ? __('Begynner med') : __('Inneholder') }}
                    · {{ $this->effectLabel($rule->effect) }}
                    @if ($rule->category)
                        · {{ $rule->category->value }}
                    @endif
                </flux:text>
                <div class="mt-3 flex gap-2">
                    <flux:button size="sm" variant="subtle" wire:click="edit({{ $rule->id }})">{{ __('Endre') }}</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="delete({{ $rule->id }})">{{ __('Slett') }}</flux:button>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Ingen regler matcher søket.') }}</flux:text>
        @endforelse
    </div>
</div>
