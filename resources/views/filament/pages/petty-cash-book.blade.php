<x-filament-panels::page>
    {{ $this->form }}

    @php($summary = $this->getSummary())
    @php($monthValue = $this->selectedMonth()->format('Y-m'))
    @php($totalReceived = $summary['opening_balance'] + $summary['received_total'])
    @php($toReplenish = max(0, $summary['float_amount'] - $summary['closing_balance']))

    {{-- Summary stat cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-reports.stat-card
            label="Imprest Float"
            :value="number_format($summary['float_amount'], 2)"
            help="Configured float amount"
        />
        <x-reports.stat-card
            label="Total Received"
            :value="number_format($totalReceived, 2)"
            color="success"
            help="Opening b/d + top-ups"
        />
        <x-reports.stat-card
            label="Total Paid"
            :value="number_format($summary['paid_total'], 2)"
            color="danger"
            help="Vouchers this month"
        />
        <x-reports.stat-card
            label="Closing Balance (c/d)"
            :value="number_format($summary['closing_balance'], 2)"
            :color="$summary['closing_balance'] < 0 ? 'danger' : 'primary'"
            :help="$toReplenish > 0 ? number_format($toReplenish, 2) . ' to replenish' : 'Float intact'"
        />
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ $summary['month'] }} · Petty Cash Book</x-slot>
        <x-slot name="description">Imprest float {{ number_format($summary['float_amount'], 2) }}</x-slot>
        <x-slot name="headerEnd">
            @if($summary['replenished'])
                <x-filament::badge color="success" icon="heroicon-m-check-circle">Replenished</x-filament::badge>
            @else
                <x-filament::badge color="warning" icon="heroicon-m-clock">Open</x-filament::badge>
            @endif
        </x-slot>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Received side --}}
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Received</h3>
                <div class="overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr class="text-left">
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Details</th>
                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            <tr>
                                <td class="px-3 py-2">{{ \Carbon\Carbon::parse($monthValue.'-01')->format('M j') }}</td>
                                <td class="px-3 py-2">b/d</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($summary['opening_balance'], 2) }}</td>
                            </tr>
                            @foreach($summary['received'] as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                                    <td class="px-3 py-2">{{ $row['details'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-success-600 dark:text-success-400">{{ number_format($row['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                                <td class="px-3 py-2" colspan="2">Total received</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($totalReceived, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- Paid side --}}
            <div class="lg:col-span-2">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Paid</h3>
                <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr class="text-left">
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Voucher</th>
                                <th class="px-3 py-2 font-medium">Details</th>
                                <th class="px-3 py-2 text-center font-medium">Attachment</th>
                                <th class="px-3 py-2 text-right font-medium">Total Paid</th>
                                @foreach($summary['columns'] as $col)
                                    <th class="px-3 py-2 text-right font-medium">{{ $col }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse($summary['paid'] as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                                    <td class="px-3 py-2">
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-white/10">{{ $row['voucher_no'] }}</span>
                                    </td>
                                    <td class="px-3 py-2">{{ $row['details'] }}</td>
                                    <td class="px-3 py-2 text-center">
                                        @if(filled($row['receipt_path']))
                                            {{ ($this->viewReceiptAction)(['voucher' => $row['id']]) }}
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($row['amount'], 2) }}</td>
                                    @foreach($summary['columns'] as $col)
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $row['column'] === $col ? number_format($row['amount'], 2) : '' }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td class="px-3 py-6 text-center text-gray-400" colspan="{{ 5 + count($summary['columns']) }}">No vouchers this month.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-100 text-gray-500 dark:border-white/5 dark:text-gray-400">
                                <td class="px-3 py-2" colspan="4">c/d (closing balance)</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($summary['closing_balance'], 2) }}</td>
                                @foreach($summary['columns'] as $col)<td></td>@endforeach
                            </tr>
                            <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                                <td class="px-3 py-2" colspan="4">Totals</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($summary['paid_total'], 2) }}</td>
                                @foreach($summary['columns'] as $col)
                                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format($summary['column_totals'][$col] ?? 0, 2) }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        @if($summary['closing_balance'] !== $summary['ledger_balance'])
            <x-filament::callout color="danger" icon="heroicon-m-exclamation-triangle" class="mt-4">
                <x-slot name="description">
                    Book c/d {{ number_format($summary['closing_balance'], 2) }} disagrees with ledger 1150 balance {{ number_format($summary['ledger_balance'], 2) }} — investigate manual entries.
                </x-slot>
            </x-filament::callout>
        @endif
    </x-filament::section>
</x-filament-panels::page>
