{{--
    Where the money went, as proportions.

    A table of thirty account totals answers "how much was each" and leaves the
    reader to work out "which ones mattered" by scanning and comparing numbers.
    This says it at a glance: largest first, each as a share of the whole.

    Deliberately CSS rather than a charting library. There is no script, nothing
    to hydrate, it inherits the theme in both light and dark, and it survives
    being printed — which a canvas does not. A pie of thirty slices would also be
    unreadable, and thirty is a normal number of expense accounts.

    @param string $heading
    @param array<int, array{code?: string, name: string, amount: float}> $rows
    @param float  $total
    @param string $color  Tailwind palette name for the bars
    @param int    $limit  Rows shown before the rest is grouped
--}}
@props([
    'heading',
    'rows' => [],
    'total' => 0.0,
    'color' => 'primary',
    'limit' => 8,
])

@php
    // Negatives cannot be a share of a whole, and an expense account with a net
    // credit for the period (a refund larger than the spend) is a real thing.
    // Excluded from the proportions rather than drawn as a bar of nonsense, and
    // said so below when it happens.
    $positive = collect($rows)
        ->filter(fn (array $row): bool => $row['amount'] > 0)
        ->sortByDesc('amount')
        ->values();

    $base = round((float) $positive->sum('amount'), 2);
    $shown = $positive->take($limit);
    $rest = $positive->slice($limit);
    $negatives = collect($rows)->filter(fn (array $row): bool => $row['amount'] < 0)->count();

    $share = fn (float $amount): float => $base > 0 ? round($amount / $base * 100, 1) : 0.0;

    // Written out rather than interpolated as "bg-{$color}-500". Tailwind finds
    // classes by scanning source text for literal strings, so an interpolated
    // one is never compiled and the bar renders with no colour at all — which
    // looks like a styling nit and is actually the class simply not existing.
    $barClass = [
        'primary' => 'bg-primary-500',
        'success' => 'bg-success-500',
        'danger' => 'bg-danger-500',
        'warning' => 'bg-warning-500',
    ][$color] ?? 'bg-primary-500';
@endphp

@if($positive->isNotEmpty())
    <x-filament::section collapsible>
        <x-slot name="heading">{{ $heading }}</x-slot>

        <div class="space-y-2.5">
            @foreach($shown as $row)
                <div>
                    <div class="flex items-baseline justify-between gap-4 text-sm">
                        <span class="truncate text-gray-700 dark:text-gray-200">
                            @if(! empty($row['code']))
                                <span class="text-gray-400 dark:text-gray-500">{{ $row['code'] }}</span>
                            @endif
                            {{ $row['name'] }}
                        </span>
                        <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                            {{ number_format($row['amount'], 2) }}
                            <span class="ml-1 font-medium text-gray-700 dark:text-gray-200">{{ number_format($share($row['amount']), 1) }}%</span>
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded bg-gray-100 dark:bg-white/5">
                        <div class="h-full rounded {{ $barClass }}"
                             style="width: {{ max($share($row['amount']), 0.5) }}%"></div>
                    </div>
                </div>
            @endforeach

            @if($rest->isNotEmpty())
                @php($restTotal = round((float) $rest->sum('amount'), 2))
                <div>
                    <div class="flex items-baseline justify-between gap-4 text-sm">
                        {{-- Counted, not just "other": how many accounts are hidden
                             behind one bar is the difference between a tidy tail
                             and something worth opening. --}}
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ $rest->count() }} smaller {{ \Illuminate\Support\Str::plural('account', $rest->count()) }}
                        </span>
                        <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                            {{ number_format($restTotal, 2) }}
                            <span class="ml-1 font-medium">{{ number_format($share($restTotal), 1) }}%</span>
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded bg-gray-100 dark:bg-white/5">
                        <div class="h-full rounded bg-gray-400 dark:bg-gray-500"
                             style="width: {{ max($share($restTotal), 0.5) }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        @if($negatives > 0)
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ $negatives }} {{ \Illuminate\Support\Str::plural('account', $negatives) }}
                {{ $negatives === 1 ? 'has' : 'have' }} a net balance the other way for this
                period — a refund or a correction larger than the activity. Left out of the
                shares above, because a negative is not a proportion of a whole; the table
                still has them.
            </p>
        @endif
    </x-filament::section>
@endif
