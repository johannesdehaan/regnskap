<?php

use App\Models\Account;
use App\Support\AccountNumbers;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Kontoer')] class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'brukskonto';

    public function mount(): void
    {
        $this->authorize('viewAny', Account::class);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Auth::user()->accounts()->orderBy('name')->get();
    }

    /**
     * An account named like a payment method that a synced bank account is
     * mapped to is listed with that bank account's number.
     */
    #[Computed]
    public function accountNumbers(): AccountNumbers
    {
        return new AccountNumbers(Auth::user());
    }

    public function edit(int $id): void
    {
        $account = Account::query()->findOrFail($id);
        $this->authorize('update', $account);

        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->type = $account->type;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->type = 'brukskonto';
        $this->reset('name');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
        ]);

        if ($this->editingId !== null) {
            $account = Account::query()->findOrFail($this->editingId);
            $this->authorize('update', $account);
            $account->update($validated);
        } else {
            $this->authorize('create', Account::class);
            Auth::user()->accounts()->create($validated);
        }

        Flux::toast(variant: 'success', text: __('Lagret.'));
        $this->cancel();
        unset($this->accounts);
    }

    public function delete(int $id): void
    {
        $account = Account::query()->findOrFail($id);
        $this->authorize('delete', $account);
        $account->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }

        Flux::toast(variant: 'success', text: __('Slettet.'));
        unset($this->accounts);
    }
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <flux:heading size="xl">{{ __('Kontoer') }}</flux:heading>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">{{ $editingId ? __('Rediger konto') : __('Ny konto') }}</flux:heading>
        <flux:input wire:model="name" :label="__('Navn')" required />
        <flux:select wire:model="type" :label="__('Type')" required>
            <option value="brukskonto">{{ __('Brukskonto') }}</option>
            <option value="sparekonto">{{ __('Sparekonto') }}</option>
            <option value="kredittkort">{{ __('Kredittkort') }}</option>
            <option value="investering">{{ __('Investering') }}</option>
            <option value="kontanter">{{ __('Kontanter') }}</option>
            <option value="annet">{{ __('Annet') }}</option>
        </flux:select>
        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
            @if ($editingId)
                <flux:button type="button" variant="subtle" wire:click="cancel">{{ __('Avbryt') }}</flux:button>
            @endif
        </div>
    </form>

    <div class="space-y-3">
        @forelse ($this->accounts as $account)
            <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="sm">{{ $this->accountNumbers->label($account->name) }}</flux:heading>
                    <flux:text class="text-sm">{{ $account->type }}</flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="subtle" wire:click="edit({{ $account->id }})">{{ __('Rediger') }}</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Slette denne?') }}">{{ __('Slett') }}</flux:button>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Ingen kontoer ennå.') }}</flux:text>
        @endforelse
    </div>
</div>
