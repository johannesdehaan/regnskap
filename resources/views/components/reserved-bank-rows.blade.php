@props(['rows'])

@if ($rows->isNotEmpty())
    <div class="space-y-2 opacity-60" data-test="reserved-rows">
        <flux:heading size="sm">{{ __('Reservert hos banken') }}</flux:heading>
        <flux:text class="text-sm">
            {{ __('Upålitelig øyeblikksbilde fra siste synk. Radene kan endre seg eller forsvinne, og blir først transaksjoner når banken har bokført dem.') }}
        </flux:text>
        <div class="space-y-2">
            @foreach ($rows as $row)
                <div class="rounded-xl border border-dashed border-zinc-300 px-3 py-2 dark:border-zinc-600" data-test="reserved-row">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm">{{ $row->payee }}</p>
                        <p class="text-sm tabular-nums">
                            {{ number_format(abs((float) $row->amount), 2, ',', ' ') }} kr
                            @if ((float) $row->amount > 0)
                                {{ __('inn') }}
                            @endif
                        </p>
                    </div>
                    <p class="text-xs">
                        {{ $row->booked_on->format('d.m.Y') }}
                        @if ($row->suggested_category)
                            · {{ $row->suggested_category->value }}
                        @endif
                        · {{ __('ikke bokført') }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
@endif
