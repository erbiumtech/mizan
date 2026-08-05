@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $currency = $this->currency();
    // Only the late buckets: "current" is already the Open figure above them, and
    // repeating it as a chip invites reading the two as separate money.
    $overdueBuckets = ['31-60' => '31–60 days', '61-90' => '61–90 days', '90+' => '90+ days'];
@endphp

{{-- x-filament-widgets::widget, not a bare div: that component is what applies
     $columnSpan to the dashboard grid. Without it the widget renders inside one
     column whatever the property says, which reads as a styling accident rather
     than the missing wrapper it is. --}}
<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @foreach($this->panels() as $panel)
            <x-filament::section>
                <x-slot name="heading">{{ $panel['title'] }}</x-slot>

                <x-slot name="description">{{ $panel['blurb'] }}</x-slot>

                @if($panel['report_url'])
                    <x-slot name="headerEnd">
                        <x-filament::link :href="$panel['report_url']" size="sm">
                            View report
                        </x-filament::link>
                    </x-slot>
                @endif

                @if($panel['count'] === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nothing outstanding — every issued
                        {{ $panel['title'] === 'Receivables' ? 'invoice' : 'bill' }} is paid.
                    </p>
                @else
                    <div class="flex flex-wrap items-baseline gap-x-2 text-sm text-gray-500 dark:text-gray-400">
                        <span>Total unpaid:</span>
                        <span class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ $currency }} {{ $money($panel['total']) }}
                        </span>
                        <span class="text-xs">
                            {{ $panel['count'] }} {{ \Illuminate\Support\Str::plural($panel['title'] === 'Receivables' ? 'invoice' : 'bill', $panel['count']) }}
                        </span>
                    </div>

                    {{-- Proportion of the total that is late, at a glance. Two segments
                         rather than a bar per aging bucket: the decision this panel
                         supports is "is any of this late", and the buckets below answer
                         "how late" for anyone who wants it. --}}
                    <div
                        class="mt-3 flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"
                        role="img"
                        aria-label="{{ $panel['overdue_share'] }}% of {{ strtolower($panel['title']) }} overdue"
                    >
                        @if($panel['open'] > 0)
                            <div class="h-full bg-warning-400" style="width: {{ $panel['open_share'] }}%"></div>
                        @endif
                        @if($panel['overdue'] > 0)
                            <div class="h-full bg-danger-500" style="width: {{ $panel['overdue_share'] }}%"></div>
                        @endif
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="size-2 rounded-full bg-warning-400"></span>
                                <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Open</span>
                            </div>
                            <div class="mt-0.5 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ $currency }} {{ $money($panel['open']) }}
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                {{ $panel['open_count'] }} within terms
                            </p>
                        </div>

                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="size-2 rounded-full bg-danger-500"></span>
                                <span class="text-xs font-medium text-danger-600 dark:text-danger-400">Overdue</span>
                            </div>
                            <div class="mt-0.5 text-lg font-semibold tabular-nums {{ $panel['overdue'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">
                                {{ $currency }} {{ $money($panel['overdue']) }}
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                {{ $panel['overdue_count'] }} past due
                            </p>
                        </div>
                    </div>

                    @if($panel['overdue'] > 0)
                        <div class="mt-3 flex flex-wrap gap-1.5 border-t border-gray-100 pt-3 dark:border-white/5">
                            @foreach($overdueBuckets as $bucket => $label)
                                @if(($panel['buckets'][$bucket] ?? 0) > 0)
                                    <x-filament::badge :color="$bucket === '90+' ? 'danger' : 'warning'">
                                        {{ $label }}: {{ $money($panel['buckets'][$bucket]) }}
                                    </x-filament::badge>
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
