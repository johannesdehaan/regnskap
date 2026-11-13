<?php

use App\Actions\BuildFoodBudget;
use App\Actions\SplitTransaction;
use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use App\Models\CategoryRule;
use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Innboks')] class extends Component
{
    public ?int $transactionId = null;

    public bool $splitting = false;

    public string $splitAmount = '';

    public string $splitCategoryA = '';

    public string $splitCategoryB = '';

    public bool $saveAsRule = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Transaction::class);
        $this->loadNext();
    }

    public function loadNext(): void
    {
        $next = Auth::user()->transactions()->inbox()->orderBy('booked_on')->orderBy('id')->first();

        $this->transactionId = $next?->id;
        $this->splitting = false;
        $this->saveAsRule = false;
        $this->reset('splitAmount', 'splitCategoryA', 'splitCategoryB');
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function foodBudget(): array
    {
        return app(BuildFoodBudget::class)(Auth::user());
    }

    #[Computed]
    public function transaction(): ?Transaction
    {
        if ($this->transactionId === null) {
            return null;
        }

        $transaction = Auth::user()->transactions()->find($this->transactionId);

        if ($transaction instanceof Transaction) {
            $this->authorize('view', $transaction);
        }

        return $transaction;
    }

    #[Computed]
    public function remaining(): int
    {
        return Auth::user()->transactions()->inbox()->count();
    }

    #[Computed]
    public function suggestion(): ?Category
    {
        $transaction = $this->transaction;

        if (! $transaction instanceof Transaction) {
            return null;
        }

        return CategoryRule::suggestFor($transaction->payee)?->category;
    }

    public function currentForUpdate(): Transaction
    {
        $transaction = Transaction::query()->findOrFail($this->transactionId);

        $this->authorize('update', $transaction);

        return $transaction;
    }

    public function assign(string $category): void
    {
        $transaction = $this->currentForUpdate();

        $chosen = Category::from($category);

        $transaction->update([
            'category' => $chosen,
            'needs_review' => false,
        ]);

        if ($this->saveAsRule && trim($transaction->payee) !== '') {
            Auth::user()->foodRules()->updateOrCreate(
                [
                    'pattern' => trim($transaction->payee),
                    'match_type' => FoodRuleMatch::Contains->value,
                ],
                [
                    'effect' => $chosen->countsAsFood() ? FoodRuleEffect::Count : FoodRuleEffect::Exclude,
                    'category' => $chosen,
                    'priority' => 110,
                ],
            );
        }

        unset($this->foodBudget);
        $this->loadNext();
    }

    public function skip(): void
    {
        $currentId = $this->transactionId;

        $next = Auth::user()->transactions()->inbox()
            ->when($currentId, fn ($query) => $query->where('id', '!=', $currentId))
            ->orderBy('booked_on')
            ->orderBy('id')
            ->first();

        $this->transactionId = $next?->id ?? $currentId;
        $this->splitting = false;
    }

    public function startSplit(): void
    {
        $this->splitting = true;
    }

    public function split(SplitTransaction $splitter): void
    {
        $transaction = $this->currentForUpdate();

        $this->validate([
            'splitAmount' => ['required', 'numeric', 'gt:0'],
            'splitCategoryA' => ['required'],
            'splitCategoryB' => ['required'],
        ]);

        $splitter(
            $transaction,
            $this->splitAmount,
            Category::from($this->splitCategoryA),
            Category::from($this->splitCategoryB),
        );

        Flux::toast(variant: 'success', text: __('Delt i to.'));

        $this->loadNext();
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <x-food-budget-summary :status="$this->foodBudget" linked />

    <div class="flex items-baseline justify-between gap-4">
        <flux:heading size="xl">{{ __('Innboks') }}</flux:heading>
        <flux:text>{{ $this->remaining }} {{ __('igjen') }}</flux:text>
    </div>

    @if ($this->transaction)
        <div class="space-y-2 text-center">
            <p class="text-4xl font-semibold tracking-tight tabular-nums">
                {{ number_format((float) $this->transaction->amount, 2, ',', ' ') }} kr
            </p>
            <flux:heading size="lg">{{ $this->transaction->payee }}</flux:heading>
            @if ($this->transaction->needs_review)
                <p class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ __('Må vurderes') }}</p>
            @endif
            <flux:text>
                {{ $this->transaction->booked_on->format('d.m.Y') }}
                @if ($this->transaction->category)
                    · {{ $this->transaction->category->value }}
                @endif
                @if ($this->transaction->subcategory)
                    · {{ $this->transaction->subcategory->value }}
                @endif
                @if ($this->transaction->payment_method)
                    · {{ $this->transaction->payment_method->value }}
                @endif
            </flux:text>
            @if ($this->transaction->children()->exists())
                <flux:text>{{ __('Beløpet er endret etter deling. En ny deling erstatter de gamle linjene.') }}</flux:text>
            @endif
            @if ($this->transaction->note)
                <flux:text class="text-sm">{{ $this->transaction->note }}</flux:text>
            @endif
        </div>

        <div class="flex flex-wrap justify-center gap-2">
            @foreach (\App\Enums\Category::cases() as $category)
                @php($suggested = $this->suggestion?->value === $category->value)
                <flux:button
                    type="button"
                    size="sm"
                    :variant="$suggested ? 'primary' : 'ghost'"
                    wire:click="assign('{{ $category->value }}')"
                    :data-suggested="$suggested ? '1' : null"
                >
                    {{ $category->value }}
                    @if ($suggested)
                        <span class="ms-1 text-xs font-medium">{{ __('Forslag') }}</span>
                    @endif
                </flux:button>
            @endforeach
        </div>

        <label class="flex items-center justify-center gap-2 text-sm">
            <input type="checkbox" wire:model="saveAsRule" class="rounded border-zinc-300 dark:border-zinc-600" />
            <span>{{ __('Lagre som regel for dette brukerstedet') }}</span>
        </label>

        <div class="flex justify-center gap-3">
            <flux:button variant="subtle" wire:click="skip">{{ __('Hopp over') }}</flux:button>
            <flux:button variant="subtle" wire:click="startSplit">{{ __('Del opp') }}</flux:button>
        </div>

        @if ($splitting)
            <form wire:submit="split" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('Del i to kategorier') }}</flux:heading>
                <flux:input
                    wire:model="splitAmount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    :label="__('Beløp i første del')"
                    required
                />
                <flux:select wire:model="splitCategoryA" :label="__('Første kategori')" required>
                    <option value="">{{ __('Velg…') }}</option>
                    @foreach (\App\Enums\Category::cases() as $category)
                        <option value="{{ $category->value }}">{{ $category->value }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="splitCategoryB" :label="__('Andre kategori')" required>
                    <option value="">{{ __('Velg…') }}</option>
                    @foreach (\App\Enums\Category::cases() as $category)
                        <option value="{{ $category->value }}">{{ $category->value }}</option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" variant="primary">{{ __('Del') }}</flux:button>
            </form>
        @endif
    @else
        <flux:text class="text-center">
            {{ __('Innboksen er tom. Importer en CSV eller registrer en ny transaksjon.') }}
        </flux:text>
        <div class="flex justify-center gap-3">
            <flux:button :href="route('transactions.create')" wire:navigate>{{ __('Ny') }}</flux:button>
            <flux:button :href="route('transactions.import')" wire:navigate variant="subtle">{{ __('Import') }}</flux:button>
        </div>
    @endif
</div>
