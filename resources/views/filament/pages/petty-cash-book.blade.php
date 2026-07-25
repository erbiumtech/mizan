<x-filament-panels::page>
    {{ $this->form }}

    @php($summary = $this->getSummary())
    @php($monthValue = $this->selectedMonth()->format('Y-m'))

    <x-filament::section>
        <x-slot name="heading">{{ $summary['month'] }}</x-slot>
        <x-slot name="description">Imprest float {{ number_format($summary['float_amount'], 2) }}</x-slot>
        <x-slot name="headerEnd">
            @if($summary['replenished'])
                <x-filament::badge color="success">Replenished</x-filament::badge>
            @else
                <x-filament::badge color="danger">Open</x-filament::badge>
            @endif
        </x-slot>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            {{-- Received side --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr><th colspan="3" class="py-2 text-center font-semibold border-b border-gray-200 dark:border-white/10">Received</th></tr>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-1.5 pr-4 font-medium">Date</th>
                            <th class="py-1.5 pr-4 font-medium">Details</th>
                            <th class="py-1.5 pl-4 font-medium text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">{{ \Carbon\Carbon::parse($monthValue.'-01')->format('M j') }}</td>
                            <td class="py-1.5 pr-4">b/d</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($summary['opening_balance'], 2) }}</td>
                        </tr>
                        @foreach($summary['received'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                                <td class="py-1.5 pr-4">{{ $row['details'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="2" class="py-2 pr-4">Total received</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($summary['opening_balance'] + $summary['received_total'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Paid side --}}
            <div class="lg:col-span-2 overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr><th colspan="{{ 4 + count($summary['columns']) }}" class="py-2 text-center font-semibold border-b border-gray-200 dark:border-white/10">Paid</th></tr>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-1.5 pr-4 font-medium">Date</th>
                            <th class="py-1.5 pr-4 font-medium">Voucher</th>
                            <th class="py-1.5 pr-4 font-medium">Details</th>
                            <th class="py-1.5 pl-4 font-medium text-right">Total Paid</th>
                            @foreach($summary['columns'] as $col)
                                <th class="py-1.5 pl-4 font-medium text-right">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($summary['paid'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                                <td class="py-1.5 pr-4">{{ $row['voucher_no'] }}</td>
                                <td class="py-1.5 pr-4">{{ $row['details'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                                @foreach($summary['columns'] as $col)
                                    <td class="py-1.5 pl-4 text-right tabular-nums">{{ $row['column'] === $col ? number_format($row['amount'], 2) : '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ 4 + count($summary['columns']) }}" class="py-1.5 px-2 text-gray-400">No vouchers this month.</td></tr>
                        @endforelse
                        <tr class="border-b border-gray-200 dark:border-white/10 font-medium">
                            <td colspan="3" class="py-1.5 pr-4">c/d (closing balance)</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($summary['closing_balance'], 2) }}</td>
                            @foreach($summary['columns'] as $col)<td></td>@endforeach
                        </tr>
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="3" class="py-2 pr-4">Totals</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($summary['paid_total'], 2) }}</td>
                            @foreach($summary['columns'] as $col)
                                <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($summary['column_totals'][$col] ?? 0, 2) }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        @if($summary['closing_balance'] !== $summary['ledger_balance'])
            <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                Book c/d {{ number_format($summary['closing_balance'], 2) }} disagrees with ledger 1150 balance {{ number_format($summary['ledger_balance'], 2) }} — investigate manual entries.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
