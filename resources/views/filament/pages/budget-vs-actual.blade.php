<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())

    @if($report === null)
        <x-filament::section>
            <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                <p class="font-medium text-gray-700 dark:text-gray-200">No budget selected</p>
                <p class="mt-1">Create one under Accounting &rarr; Budgets, then choose it above.</p>
            </div>
        </x-filament::section>
    @else
        @if(! $report['has_plan'])
            {{-- A budget with no lines and a budget met exactly both total zero.
                 Saying which one this is costs a sentence. --}}
            <x-filament::section>
                <div class="py-4 text-sm text-warning-600 dark:text-warning-400">
                    This budget has no accounts planned yet, so everything below is
                    shown as unbudgeted spending rather than as a variance.
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">
                {{ $report['budget']->name }}
            </x-slot>

            <x-slot name="description">
                {{ \Carbon\Carbon::parse($report['from'])->format('d M Y') }} —
                {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}.
                A part month counts for the days in it, so the plan you are measured
                against ends on the same day your spending does.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">Code</th>
                            <th class="py-2 pr-4 font-medium">Account</th>
                            <th class="py-2 px-4 font-medium text-right">Full year</th>
                            <th class="py-2 px-4 font-medium text-right">Planned</th>
                            <th class="py-2 px-4 font-medium text-right">Actual</th>
                            <th class="py-2 px-4 font-medium text-right">Variance</th>
                            <th class="py-2 pl-4 font-medium text-right">% of year</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report['sections'] as $section)
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <td colspan="7" class="py-2 px-2 font-semibold uppercase text-xs tracking-wide">
                                    {{ $section['type'] === 'income' ? 'Income' : 'Expenses' }}
                                </td>
                            </tr>

                            @forelse($section['rows'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-4">{{ $row['code'] }}</td>
                                    <td class="py-1.5 pr-4">
                                        {{ $row['name'] }}
                                        @if($row['unplanned'])
                                            <span class="ml-1 rounded bg-warning-100 px-1.5 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                                                unbudgeted
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-1.5 px-4 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['full_year'], 2) }}</td>
                                    <td class="py-1.5 px-4 text-right tabular-nums">{{ number_format($row['planned'], 2) }}</td>
                                    <td class="py-1.5 px-4 text-right tabular-nums">{{ number_format($row['actual'], 2) }}</td>
                                    <td @class([
                                        'py-1.5 px-4 text-right tabular-nums font-medium',
                                        'text-success-600 dark:text-success-400' => $row['variance'] > 0,
                                        'text-danger-600 dark:text-danger-400' => $row['variance'] < 0,
                                    ])>{{ number_format($row['variance'], 2) }}</td>
                                    <td class="py-1.5 pl-4 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $row['used_percent'] === null ? '—' : number_format($row['used_percent'], 1).'%' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-1.5 px-2 text-gray-400">
                                        Nothing planned or spent under {{ $section['type'] }}
                                    </td>
                                </tr>
                            @endforelse

                            <tr class="border-b border-gray-200 dark:border-white/10 font-medium">
                                <td colspan="2" class="py-1.5 pr-4">
                                    Total {{ $section['type'] === 'income' ? 'Income' : 'Expenses' }}
                                </td>
                                <td class="py-1.5 px-4 text-right tabular-nums">{{ number_format($section['full_year'], 2) }}</td>
                                <td class="py-1.5 px-4 text-right tabular-nums">{{ number_format($section['planned'], 2) }}</td>
                                <td class="py-1.5 px-4 text-right tabular-nums">{{ number_format($section['actual'], 2) }}</td>
                                <td @class([
                                    'py-1.5 px-4 text-right tabular-nums',
                                    'text-success-600 dark:text-success-400' => $section['variance'] > 0,
                                    'text-danger-600 dark:text-danger-400' => $section['variance'] < 0,
                                ])>{{ number_format($section['variance'], 2) }}</td>
                                <td></td>
                            </tr>
                        @endforeach

                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="3" class="py-2 pr-4">Net — planned {{ $report['net_planned'] < 0 ? 'loss' : 'profit' }} against actual</td>
                            <td class="py-2 px-4 text-right tabular-nums">{{ number_format($report['net_planned'], 2) }}</td>
                            <td class="py-2 px-4 text-right tabular-nums">{{ number_format($report['net_actual'], 2) }}</td>
                            <td @class([
                                'py-2 px-4 text-right tabular-nums',
                                'text-success-600 dark:text-success-400' => $report['net_actual'] >= $report['net_planned'],
                                'text-danger-600 dark:text-danger-400' => $report['net_actual'] < $report['net_planned'],
                            ])>{{ number_format($report['net_actual'] - $report['net_planned'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                A positive variance is good news in both halves: under plan on an
                expense, over plan on income.
            </p>
        </x-filament::section>

        @if($report['has_plan'])
            <x-filament::section collapsible>
                <x-slot name="heading">Month by month</x-slot>
                <x-slot name="description">
                    Planned and actual net of income less expenses, for each month of
                    the year. Months that have not been reported on yet show no actual.
                </x-slot>

                @php($scale = collect($report['monthly'])->flatMap(fn ($m) => [abs($m['planned']), abs($m['actual'] ?? 0)])->max() ?: 1)

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                                <th class="py-2 pr-4 font-medium">Month</th>
                                <th class="py-2 px-4 font-medium text-right">Planned</th>
                                <th class="py-2 px-4 font-medium text-right">Actual</th>
                                <th class="py-2 pl-4 font-medium w-1/2">Plan against actual</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($report['monthly'] as $month)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-4 whitespace-nowrap">{{ $month['label'] }}</td>
                                    <td class="py-1.5 px-4 text-right tabular-nums">{{ number_format($month['planned'], 2) }}</td>
                                    <td class="py-1.5 px-4 text-right tabular-nums">
                                        {{ $month['actual'] === null ? '—' : number_format($month['actual'], 2) }}
                                    </td>
                                    <td class="py-1.5 pl-4">
                                        {{-- Two plain bars on a shared scale. A chart
                                             library would be a dependency and a script
                                             tag for something a div can say. --}}
                                        <div class="space-y-1">
                                            <div class="h-2 rounded bg-gray-200 dark:bg-white/10"
                                                 style="width: {{ round(abs($month['planned']) / $scale * 100, 1) }}%"
                                                 title="Planned {{ number_format($month['planned'], 2) }}"></div>
                                            <div @class([
                                                    'h-2 rounded',
                                                    'bg-success-500' => ($month['actual'] ?? 0) >= $month['planned'],
                                                    'bg-danger-500' => ($month['actual'] ?? 0) < $month['planned'],
                                                 ])
                                                 style="width: {{ round(abs($month['actual'] ?? 0) / $scale * 100, 1) }}%"
                                                 title="Actual {{ $month['actual'] === null ? 'not yet' : number_format($month['actual'], 2) }}"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
