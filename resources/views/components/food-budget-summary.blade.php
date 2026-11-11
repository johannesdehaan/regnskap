@props(['status', 'linked' => false])

@php
    $kroner = function (string $amount): string {
        $value = (float) $amount;
        $decimals = abs($value - round($value)) < 0.001 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ');
    };
    $over = (bool) $status['over_limit'];
@endphp

<div @class([
    'rounded-2xl border p-4',
    'border-red-300 bg-red-50 text-red-950 dark:border-red-800 dark:bg-red-950 dark:text-red-50' => $over,
    'border-emerald-300 bg-emerald-50 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-50' => ! $over,
])>
    <div class="flex items-baseline justify-between gap-3">
        @if ($linked)
            <a href="{{ route('mat.index') }}" wire:navigate class="text-sm font-medium underline-offset-2 hover:underline">
                {{ __('Matbudsjett') }}
            </a>
        @else
            <p class="text-sm font-medium">{{ __('Matbudsjett') }}</p>
        @endif
        <p class="text-xs tabular-nums" data-test="food-status">
            {{ $over ? __('Over grensen') : __('Under grensen') }}
        </p>
    </div>

    <p class="mt-2 text-4xl font-semibold tracking-tight tabular-nums" data-test="food-remaining">
        {{ $kroner($status['remaining_today']) }} kr
    </p>
    <p class="mt-1 text-sm">{{ __('igjen å bruke i dag') }}</p>

    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div>
            <p class="text-xs opacity-80">{{ __('Brukt siste :days dager', ['days' => $status['window_days']]) }}</p>
            <p class="font-semibold tabular-nums" data-test="food-spent">{{ $kroner($status['spent']) }} kr</p>
        </div>
        <div>
            <p class="text-xs opacity-80">{{ __('Grense') }}</p>
            <p class="font-semibold tabular-nums">{{ $kroner($status['amount']) }} kr</p>
        </div>
    </div>

    <p class="mt-3 text-sm" data-test="food-pending">
        {{ __('Venter på vurdering') }}:
        <span class="font-semibold tabular-nums">{{ $kroner($status['pending_review']) }} kr</span>
    </p>
</div>
