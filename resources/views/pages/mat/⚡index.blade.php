<?php

use App\Actions\BuildFoodBudget;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Matbudsjett')] class extends Component
{
    public string $amount = '';

    public int $windowDays = 7;

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

        unset($this->status);

        Flux::toast(variant: 'success', text: __('Matbudsjettet er oppdatert.'));
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
}; ?>

<div class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4">
    <div class="space-y-1">
        <flux:heading size="xl">{{ __('Matbudsjett') }}</flux:heading>
        <flux:text>
            {{ __(':amount kr per :days dager, i norsk tid.', [
                'amount' => $this->kroner($this->status['amount']),
                'days' => $this->status['window_days'],
            ]) }}
        </flux:text>
    </div>

    <x-food-budget-summary :status="$this->status" />

    <div class="space-y-2">
        <flux:heading size="sm">{{ __('Dag for dag') }}</flux:heading>
        <div class="space-y-2">
            @foreach ($this->status['days'] as $day)
                <div class="flex items-baseline justify-between gap-3 rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700" data-test="food-day">
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
            @endforeach
        </div>
    </div>

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
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <form wire:submit="saveSettings" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="sm">{{ __('Innstillinger') }}</flux:heading>
        <flux:input wire:model="amount" type="number" step="0.01" min="0" :label="__('Beløp per periode (kr)')" required />
        <flux:input wire:model="windowDays" type="number" min="1" max="62" :label="__('Antall dager i vinduet')" required />
        <flux:button type="submit" variant="primary">{{ __('Lagre') }}</flux:button>
    </form>

    <flux:button :href="route('mat.rules')" wire:navigate variant="subtle">{{ __('Rediger gjenkjenningsregler') }}</flux:button>
</div>
