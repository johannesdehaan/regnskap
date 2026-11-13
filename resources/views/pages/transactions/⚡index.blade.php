<?php

use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transaksjoner')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $month = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $payment_method = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Transaction::class);

        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedPaymentMethod(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        $query = Auth::user()->transactions()->excludingSplitParents()->orderByDesc('booked_on')->orderByDesc('id');

        if ($this->month !== '' && preg_match('/^\d{4}-\d{2}$/', $this->month) === 1) {
            $start = $this->month.'-01';
            $end = \Illuminate\Support\Carbon::parse($start)->endOfMonth()->toDateString();
            $query->whereDate('booked_on', '>=', $start)->whereDate('booked_on', '<=', $end);
        }

        if ($this->category !== '') {
            $query->where('category', $this->category);
        }

        if ($this->payment_method !== '') {
            $query->where('payment_method', $this->payment_method);
        }

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('payee', 'like', $term)->orWhere('note', 'like', $term);
            });
        }

        return $query->paginate(20);
    }

    public function toggleExcluded(int $id): void
    {
        $transaction = Auth::user()->transactions()->findOrFail($id);
        $this->authorize('update', $transaction);
        $transaction->update(['exclude_from_budget' => ! $transaction->exclude_from_budget]);
    }

    public function toggleReview(int $id): void
    {
        $transaction = Auth::user()->transactions()->findOrFail($id);
        $this->authorize('update', $transaction);
        $transaction->update(['needs_review' => ! $transaction->needs_review]);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6 py-4">
    <flux:heading size="xl">{{ __('Transaksjoner') }}</flux:heading>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <flux:input wire:model.live="month" type="month" :label="__('Måned')" />
        <flux:input wire:model.live.debounce.300ms="search" type="search" :label="__('Søk')" :placeholder="__('Brukersted eller notat')" />
        <flux:select wire:model.live="category" :label="__('Kategori')">
            <option value="">{{ __('Alle') }}</option>
            @foreach (\App\Enums\Category::cases() as $category)
                <option value="{{ $category->value }}">{{ $category->value }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="payment_method" :label="__('Betalingsmåte')">
            <option value="">{{ __('Alle') }}</option>
            @foreach (\App\Enums\PaymentMethod::cases() as $method)
                <option value="{{ $method->value }}">{{ $method->value }}</option>
            @endforeach
        </flux:select>
    </div>

    <div class="space-y-3">
        @forelse ($this->transactions as $transaction)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-baseline justify-between gap-3">
                    <flux:heading size="sm">{{ $transaction->payee }}</flux:heading>
                    <p class="text-base font-semibold tabular-nums">{{ number_format((float) $transaction->amount, 2, ',', ' ') }}</p>
                </div>
                <flux:text class="mt-1 text-sm">
                    {{ $transaction->booked_on->format('d.m.Y') }}
                    · {{ $transaction->category?->value ?? __('Ukategorisert') }}
                    @if ($transaction->subcategory)
                        · {{ $transaction->subcategory->value }}
                    @endif
                    @if ($transaction->payment_method)
                        · {{ $transaction->payment_method->value }}
                    @endif
                </flux:text>
                @if ($transaction->note)
                    <flux:text class="mt-1 text-sm">{{ $transaction->note }}</flux:text>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($transaction->needs_review)
                        <span class="text-xs font-medium text-amber-700 dark:text-amber-300">{{ __('Må vurderes') }}</span>
                    @endif
                    @if ($transaction->exclude_from_budget)
                        <span class="text-xs font-medium">{{ __('Utenfor matbudsjettet') }}</span>
                    @endif
                    <flux:button size="sm" variant="subtle" wire:click="toggleExcluded({{ $transaction->id }})">
                        {{ $transaction->exclude_from_budget ? __('Ta med i matbudsjettet') : __('Utenfor matbudsjettet') }}
                    </flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="toggleReview({{ $transaction->id }})">
                        {{ $transaction->needs_review ? __('Vurdert') : __('Må vurderes') }}
                    </flux:button>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Ingen transaksjoner matcher filtrene.') }}</flux:text>
        @endforelse
    </div>

    <div>{{ $this->transactions->links() }}</div>
</div>
