<?php

use App\Actions\ClassifyFoodPurchase;
use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Enums\Subcategory;
use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ny transaksjon')] class extends Component
{
    public string $booked_on = '';

    public string $amount = '';

    public string $payee = '';

    public ?string $category = null;

    public ?string $subcategory = null;

    public ?string $payment_method = null;

    public string $note = '';

    public bool $exclude_from_budget = false;

    public function mount(): void
    {
        $this->authorize('create', Transaction::class);
        $this->booked_on = now()->toDateString();
    }

    public function save(): void
    {
        $this->authorize('create', Transaction::class);

        $validated = $this->validate([
            'booked_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payee' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'subcategory' => ['nullable', Rule::enum(Subcategory::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string'],
        ]);

        $validated['amount'] = number_format(abs((float) $validated['amount']), 2, '.', '');
        $validated['category'] = $validated['category'] !== null && $validated['category'] !== ''
            ? Category::from($validated['category'])
            : null;
        $validated['subcategory'] = $validated['subcategory'] !== null && $validated['subcategory'] !== ''
            ? Subcategory::from($validated['subcategory'])
            : null;
        $validated['payment_method'] = $validated['payment_method'] !== null && $validated['payment_method'] !== ''
            ? PaymentMethod::from($validated['payment_method'])
            : null;

        if ($validated['subcategory'] instanceof Subcategory
            && (! $validated['category'] instanceof Category || $validated['subcategory']->category() !== $validated['category'])) {
            throw ValidationException::withMessages([
                'subcategory' => 'Underkategorien hører ikke til kategorien.',
            ]);
        }
        $validated['exclude_from_budget'] = $this->exclude_from_budget;

        $explicitCategory = $validated['category'] instanceof Category;

        if (! $explicitCategory) {
            $classification = app(ClassifyFoodPurchase::class)(
                Auth::user(),
                $validated['payee'],
                $validated['note'] !== '' ? $validated['note'] : null,
            );
            $validated['category'] = $classification->category;
            $validated['needs_review'] = $classification->needsReview;
        }

        Auth::user()->transactions()->create($validated);

        Flux::toast(variant: 'success', text: __('Transaksjonen er lagret.'));

        $this->redirect(route('inbox'), navigate: true);
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <flux:heading size="xl">{{ __('Ny transaksjon') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="booked_on" type="date" :label="__('Dato')" required />
        <flux:input wire:model="amount" type="number" step="0.01" min="0.01" :label="__('Beløp')" required />
        <flux:input wire:model="payee" type="text" :label="__('Brukersted')" required autofocus />
        <flux:select wire:model="category" :label="__('Kategori')">
            <option value="">{{ __('Ukategorisert') }}</option>
            @foreach (\App\Enums\Category::cases() as $category)
                <option value="{{ $category->value }}">{{ $category->value }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="subcategory" :label="__('Underkategori')">
            <option value="">{{ __('Ingen') }}</option>
            @foreach (\App\Enums\Subcategory::cases() as $subcategory)
                <option value="{{ $subcategory->value }}">{{ $subcategory->value }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model="payment_method" :label="__('Betalingsmåte')">
            <option value="">{{ __('Ikke valgt') }}</option>
            @foreach (\App\Enums\PaymentMethod::cases() as $method)
                <option value="{{ $method->value }}">{{ $method->value }}</option>
            @endforeach
        </flux:select>
        <flux:textarea wire:model="note" :label="__('Notat')" rows="3" />
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="exclude_from_budget" class="rounded border-zinc-300 dark:border-zinc-600" />
            <span>{{ __('Utenfor matbudsjettet') }}</span>
        </label>
        <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
    </form>
</div>
