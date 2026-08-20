<x-filament-panels::page>
    {{ $this->form }}

    @php
        $rows = $this->rows();
        $byType = $this->byType();
        $total = $this->total();
        $controlRows = $this->controlRows();
        $controlTotals = $this->controlTotals();
        $metrics = $this->metrics();
        // Every figure that can legitimately be absent prints this rather than a zero. §3.5 and §14 both turn on
        // the difference: 0.00 in the committed column reads as "nothing on order" and 0.00 in schedule variance
        // reads as "exactly on programme", and both are the reassuring wrong answer.
        $money = fn (?float $value) => $value === null ? '—' : number_format($value, 2);
    @endphp

    @if (! $this->selectedJob())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Choose a job.</p>
        </x-filament::section>
    @elseif ($rows === [] && $controlRows === [])
        <x-filament::section>
            {{-- Says which of the two it is. "No data" and "nothing spent" look identical as an empty table, and
                 a contractor reading the second when it is the first makes a decision on a wrong figure. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No budget or cost has been recorded against this job
                @if ($this->data['period_start'] ?? null) in the selected period @endif.
            </p>
        </x-filament::section>
    @else
        @if ($metrics)
            <x-filament::section heading="Earned value">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ([
                        'Budget at completion' => $money($metrics['budget_at_completion']),
                        'Earned value' => $money($metrics['earned_value']),
                        'Actual cost' => $money($metrics['actual_cost']),
                        'Cost variance' => $money($metrics['cost_variance']),
                        'CPI' => $metrics['cost_performance_index'] !== null ? number_format($metrics['cost_performance_index'], 3) : '—',
                        'Planned value' => $money($metrics['planned_value']),
                        'Schedule variance' => $money($metrics['schedule_variance']),
                        'SPI' => $metrics['schedule_performance_index'] !== null ? number_format($metrics['schedule_performance_index'], 3) : '—',
                        'Percent complete' => $metrics['percent_complete'] !== null ? number_format($metrics['percent_complete'], 2).'%' : '—',
                    ] as $label => $value)
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="text-lg font-semibold tabular-nums">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($metrics['schedule_note'])
                    {{-- §14 asks for this sentence specifically rather than a blank: the reason a schedule figure
                         is missing travels with the absence, so nobody reads the em dash as a zero. --}}
                    <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">{{ $metrics['schedule_note'] }}</p>
                @endif
            </x-filament::section>
        @endif

        @if ($controlRows !== [])
            <x-filament::section heading="Cost control">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">Code</th>
                                <th class="py-2 pr-4">Description</th>
                                <th class="py-2 pr-4 text-right">Budget</th>
                                <th class="py-2 pr-4 text-right">Committed</th>
                                <th class="py-2 pr-4 text-right">Actual</th>
                                <th class="py-2 pr-4 text-right">Accrued</th>
                                <th class="py-2 pr-4 text-right">To complete</th>
                                <th class="py-2 pr-4 text-right">Forecast final</th>
                                <th class="py-2 text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($controlRows as $row)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-2 pr-4 font-mono">{{ $row['code'] }}</td>
                                    <td class="py-2 pr-4">
                                        {{ $row['name'] }}
                                        @if ($row['eac_method'])
                                            {{-- §14: "the forecast went up" and "somebody changed the method" are
                                                 different facts, so which one produced the figure is on the line. --}}
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                ({{ str_replace('_', ' ', $row['eac_method']) }})
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $money($row['budget']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $money($row['committed']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $money($row['actual']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $money($row['accrued']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $money($row['cost_to_complete']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $money($row['forecast_final']) }}</td>
                                    <td @class([
                                        'py-2 text-right tabular-nums',
                                        'text-danger-600 dark:text-danger-400' => ($row['variance'] ?? 0) < 0,
                                    ])>{{ $money($row['variance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                                <td class="py-2 pr-4" colspan="2">Total</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($controlTotals['budget']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($controlTotals['committed']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($controlTotals['actual']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($controlTotals['accrued']) }}</td>
                                <td class="py-2 pr-4 text-right">—</td>
                                {{-- Null where any line has no forecast: a total that looks complete when it is not
                                     is how a partial forecast comes to be read as the job's. --}}
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($controlTotals['forecast_final']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $money($controlTotals['variance']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Committed is what is on order and not yet received, certified or invoiced. Zero means nothing is
                    on order against that code — a statement this report could not make before procurement existed,
                    which is why the column used to show an em dash instead.
                </p>
            </x-filament::section>
        @endif

        {{-- Materials on site — §6: delivered, costed, not yet consumed. On this page because it is the part of
             `actual` that has not been used yet: a code showing 5,000,000 spent where 3,000,000 is still stacked by
             the gate looks further through its budget than the work is, and nothing else here would say so. --}}
        @if ($this->keepsAStore())
            @php($onSite = $this->materialsOnSite())

            <x-filament::section heading="Materials on site">
                @if ($onSite === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nothing in the store. Everything delivered has been issued to the work face, which is the
                        state a store should mostly be in.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-gray-500 dark:text-gray-400">
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pr-4">Received against</th>
                                    <th class="py-2 pr-4 text-right">Quantity</th>
                                    <th class="py-2 text-right">At cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($onSite as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4">
                                            @if ($row['code'])
                                                {{ $row['code']->code }} — {{ $row['code']->name }}
                                            @else
                                                {{-- Shown rather than dropped or folded into a code: §6's failure is a
                                                     figure right in total and wrong in every breakdown. --}}
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    Not traceable to a delivery
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $row['quantity'] }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ $money($row['value']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                                    <td class="py-2 pr-4" colspan="2">On site</td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ $money($this->materialsOnSiteValue()) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                        Still in the store, so still sitting on the code it was received against — an issue is what
                        moves it to the code the material was used on. This is what it cost, not what may be claimed
                        for it on a certificate.
                    </p>
                @endif
            </x-filament::section>
        @endif

        @if ($rows !== [])
            <x-filament::section heading="By cost type">
                <div class="flex flex-wrap gap-3">
                    @foreach ($byType as $type => $amount)
                        <x-filament::badge>
                            {{ ucfirst($type) }}: {{ number_format($amount, 2) }}
                        </x-filament::badge>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="Actual cost by code">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">Code</th>
                                <th class="py-2 pr-4">Description</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4 text-right">Quantity</th>
                                <th class="py-2 pr-4 text-right">Unit rate</th>
                                <th class="py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-2 pr-4 font-mono">{{ $row['code'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst($row['cost_type']) }}</td>
                                    <td class="py-2 pr-4 text-right">
                                        {{ $row['quantity'] !== null ? number_format($row['quantity'], 2) : '—' }}
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        {{-- Null rather than zero when nothing was measured: a rate of 0.00 reads as
                                             free work, which is the wrong thing to tell somebody. --}}
                                        {{ $row['unit_rate'] !== null ? number_format($row['unit_rate'], 2) : '—' }}
                                    </td>
                                    <td class="py-2 text-right">{{ number_format($row['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                                <td class="py-2 pr-4" colspan="5">Total</td>
                                <td class="py-2 text-right">{{ number_format($total, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
