<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())

    <x-filament::section>
        <x-slot name="heading">
            @if($report['from'])
                {{ \Carbon\Carbon::parse($report['from'])->format('d M Y') }} —
            @else
                Up to
            @endif
            {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                        <th class="py-2 pr-4 font-medium">Code</th>
                        <th class="py-2 pr-4 font-medium">Account</th>
                        <th class="py-2 pl-4 font-medium text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <td colspan="3" class="py-2 px-2 font-semibold uppercase text-xs tracking-wide">Income</td>
                    </tr>
                    @forelse($report['income']['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">{{ $row['code'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['name'] }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-1.5 px-2 text-gray-400">No income in this period</td></tr>
                    @endforelse
                    <tr class="border-b border-gray-200 dark:border-white/10 font-medium">
                        <td colspan="2" class="py-1.5 pr-4">Total Income</td>
                        <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($report['income']['total'], 2) }}</td>
                    </tr>

                    <tr class="bg-gray-50 dark:bg-white/5">
                        <td colspan="3" class="py-2 px-2 font-semibold uppercase text-xs tracking-wide">Expenses</td>
                    </tr>
                    @forelse($report['expenses']['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">{{ $row['code'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['name'] }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-1.5 px-2 text-gray-400">No expenses in this period</td></tr>
                    @endforelse
                    <tr class="border-b border-gray-200 dark:border-white/10 font-medium">
                        <td colspan="2" class="py-1.5 pr-4">Total Expenses</td>
                        <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($report['expenses']['total'], 2) }}</td>
                    </tr>

                    <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                        <td colspan="2" class="py-2 pr-4">Net {{ $report['is_profit'] ? 'Profit' : 'Loss' }}</td>
                        <td class="py-2 pl-4 text-right tabular-nums {{ $report['is_profit'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ number_format(abs($report['net_profit']), 2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- The table answers "how much was each". These answer "which ones
         mattered", which is what the statement is actually opened for. --}}
    <x-reports.composition
        heading="Where the money went"
        :rows="$report['expenses']['rows']"
        :total="$report['expenses']['total']"
        color="danger"
    />

    <x-reports.composition
        heading="Where the money came from"
        :rows="$report['income']['rows']"
        :total="$report['income']['total']"
        color="success"
    />
</x-filament-panels::page>
