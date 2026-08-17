<x-filament-panels::page>
    {{ $this->form }}

    @php
        $rows = $this->rows();
        $byType = $this->byType();
        $total = $this->total();
    @endphp

    @if (! $this->selectedJob())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Choose a job.</p>
        </x-filament::section>
    @elseif ($rows === [])
        <x-filament::section>
            {{-- Says which of the two it is. "No data" and "nothing spent" look identical as an empty table, and
                 a contractor reading the second when it is the first makes a decision on a wrong figure. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No cost has been recorded against this job
                @if ($this->data['period_start'] ?? null) in the selected period @endif.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="By cost type">
            <div class="flex flex-wrap gap-3">
                @foreach ($byType as $type => $amount)
                    <x-filament::badge>
                        {{ ucfirst($type) }}: {{ number_format($amount, 2) }}
                    </x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="By cost code">
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
</x-filament-panels::page>
