<?php

use App\Actions\SyncSparebank1Transactions;
use App\Enums\BankConnectionStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\Sparebank1Exception;
use App\Models\BankAccount;
use App\Services\Sparebank1\Sparebank1Client;
use App\Support\Sparebank1Banks;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank')] class extends Component
{
    public ?string $bankStatus = null;

    public ?string $bankError = null;

    public string $statusLabel = 'Ikke tilkoblet';

    public ?string $lastSynced = null;

    public bool $showConnect = true;

    public bool $credentialsReady = false;

    public string $redirectUri = '';

    /**
     * @var list<array{id: int, name: string, number: string, type: string, sync_enabled: bool, payment_method: string}>
     */
    public array $accountRows = [];

    public function mount(Sparebank1Client $client): void
    {
        $status = session('bank_status');
        $error = session('bank_error');
        $this->bankStatus = is_string($status) ? $status : null;
        $this->bankError = is_string($error) ? $error : null;
        $this->credentialsReady = $client->configured();
        $this->redirectUri = $client->redirectUri();
        $this->loadConnection();
    }

    public function saveAccounts(): void
    {
        foreach ($this->accountRows as $index => $row) {
            if (($row['payment_method'] ?? '') === '') {
                $this->accountRows[$index]['payment_method'] = null;
            }
        }

        $validated = $this->validate([
            'accountRows' => ['array'],
            'accountRows.*.id' => ['required', 'integer'],
            'accountRows.*.sync_enabled' => ['boolean'],
            'accountRows.*.payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        foreach ($validated['accountRows'] as $row) {
            $account = BankAccount::query()
                ->where('user_id', Auth::id())
                ->whereKey($row['id'])
                ->first();

            if (! $account instanceof BankAccount) {
                continue;
            }

            $method = $row['payment_method'] ?? null;

            $account->update([
                'sync_enabled' => (bool) ($row['sync_enabled'] ?? false),
                'payment_method' => is_string($method) ? PaymentMethod::from($method) : null,
            ]);
        }

        $this->loadConnection();
        Flux::toast(variant: 'success', text: __('Kontoene er lagret.'));
    }

    public function syncNow(SyncSparebank1Transactions $sync): void
    {
        $this->bankError = null;

        try {
            $counts = $sync(Auth::user());
        } catch (Sparebank1Exception $exception) {
            $this->bankError = $exception->getMessage();
            $this->loadConnection();

            return;
        }

        $this->bankStatus = sprintf(
            'Synk ferdig. %d nye, %d oppdatert, %d hoppet over.',
            $counts['imported'],
            $counts['updated'],
            $counts['skipped'],
        );
        $this->loadConnection();
        Flux::toast(variant: 'success', text: $this->bankStatus);
    }

    public function disconnect(): void
    {
        Auth::user()->bankConnection?->forgetTokens();
        $this->bankStatus = 'SpareBank 1 er koblet fra. Tokens er slettet.';
        $this->bankError = null;
        $this->loadConnection();
        Flux::toast(variant: 'success', text: __('Koblet fra.'));
    }

    public function loadConnection(): void
    {
        $connection = Auth::user()->bankConnection;
        $this->accountRows = [];

        if ($connection === null || $connection->status === BankConnectionStatus::Disconnected) {
            $this->statusLabel = 'Ikke tilkoblet';
            $this->lastSynced = null;
            $this->showConnect = true;

            return;
        }

        $this->statusLabel = $connection->status->label();
        $this->showConnect = $connection->status === BankConnectionStatus::NeedsReauth;
        $this->lastSynced = $connection->last_synced_at?->timezone('Europe/Oslo')->format('d.m.Y H:i');

        if ($connection->last_sync_error !== null && $this->bankError === null) {
            $this->bankError = $connection->last_sync_error;
        }

        foreach ($connection->accounts()->orderBy('name')->get() as $account) {
            $this->accountRows[] = [
                'id' => $account->id,
                'name' => $account->name,
                'number' => $account->displayNumber(),
                'type' => (string) $account->account_type,
                'sync_enabled' => $account->sync_enabled,
                'payment_method' => $account->payment_method?->value ?? '',
            ];
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Bank') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Bank')" :subheading="__('Hent transaksjoner fra SpareBank 1 med BankID. Nye rader går gjennom matreglene. Overføringer mellom egne kontoer og kredittkortinnbetalinger teller ikke som forbruk.')">
        <div class="my-6 space-y-6">
            @if ($bankStatus)
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="bank-status-message">
                    <flux:text>{{ $bankStatus }}</flux:text>
                </div>
            @endif

            @if ($bankError)
                <div class="rounded-xl border border-red-200 p-4 dark:border-red-900" data-test="bank-error-message">
                    <flux:text>{{ $bankError }}</flux:text>
                </div>
            @endif

            <div class="space-y-2">
                <flux:heading size="sm">{{ __('Status') }}</flux:heading>
                <flux:text data-test="bank-connection-status">{{ $statusLabel }}</flux:text>
                <flux:text class="text-sm">
                    @if ($lastSynced)
                        {{ __('Siste synk') }}: {{ $lastSynced }}
                    @else
                        {{ __('Ikke synkronisert ennå') }}
                    @endif
                </flux:text>
            </div>

            <div class="space-y-2">
                <flux:heading size="sm">{{ __('Redirect-URL') }}</flux:heading>
                <flux:text class="break-all font-mono text-sm" data-test="bank-redirect-uri">{{ $redirectUri }}</flux:text>
            </div>

            @if (! $credentialsReady)
                <flux:text data-test="bank-missing-credentials">
                    {{ __('SPAREBANK1_CLIENT_ID og SPAREBANK1_CLIENT_SECRET mangler i miljøet. Legg dem inn i Laravel Cloud før du kobler til.') }}
                </flux:text>
            @elseif ($showConnect)
                <form method="GET" action="{{ route('sparebank1.connect') }}" class="space-y-4" data-test="bank-connect-form">
                    <flux:select name="fin_inst" :label="__('Bank')">
                        @foreach (Sparebank1Banks::options() as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary" data-test="bank-connect">
                        {{ __('Koble til med BankID') }}
                    </flux:button>
                </form>
            @endif

            @if ($accountRows !== [])
                <form wire:submit="saveAccounts" class="space-y-4" data-test="bank-accounts">
                    <flux:heading size="sm">{{ __('Kontoer') }}</flux:heading>
                    @foreach ($accountRows as $index => $account)
                        <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="bank-account-{{ $account['id'] }}">
                            <div>
                                <flux:heading size="sm">{{ $account['name'] }}</flux:heading>
                                @if ($account['number'] !== '')
                                    <flux:text class="text-sm">{{ $account['number'] }}</flux:text>
                                @endif
                            </div>
                            <flux:checkbox wire:model="accountRows.{{ $index }}.sync_enabled" :label="__('Synkroniser denne kontoen')" />
                            <flux:select wire:model="accountRows.{{ $index }}.payment_method" :label="__('Betalingsmåte')">
                                <option value="">{{ __('Ikke valgt') }}</option>
                                @foreach (PaymentMethod::cases() as $method)
                                    <option value="{{ $method->value }}">{{ $method->value }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endforeach
                    <flux:button type="submit" variant="primary" data-test="bank-save-accounts">
                        {{ __('Lagre kontoer') }}
                    </flux:button>
                </form>
            @endif

            @if (! $showConnect)
                <div class="flex flex-wrap gap-3">
                    <flux:button type="button" variant="primary" wire:click="syncNow" data-test="bank-sync-now">
                        {{ __('Synk nå') }}
                    </flux:button>
                    <flux:button type="button" variant="subtle" wire:click="disconnect" wire:confirm="{{ __('Koble fra SpareBank 1 og slette tokens?') }}" data-test="bank-disconnect">
                        {{ __('Koble fra') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
