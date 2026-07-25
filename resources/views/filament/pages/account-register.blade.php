<x-filament-panels::page>
    {{ $this->form }}

    @php($account = $this->currentAccount())
    @php($ledger = $this->getLedger())
    @php($from = $this->data['from'] ?? null)

    <x-filament::section>
        <x-slot name="heading">{{ $account->code }} {{ $account->name }}</x-slot>
        <x-slot name="description">GnuCash-style register — every transaction from one screen</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                        <th class="py-2 pr-4 font-medium">Date</th>
                        <th class="py-2 pr-4 font-medium">Num</th>
                        <th class="py-2 pr-4 font-medium">Description</th>
                        <th class="py-2 pr-4 font-medium">Transfer</th>
                        <th class="py-2 pr-4 font-medium">R</th>
                        <th class="py-2 pl-4 font-medium text-right">Debit</th>
                        <th class="py-2 pl-4 font-medium text-right">Credit</th>
                        <th class="py-2 pl-4 font-medium text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @if($from)
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-500">
                            <td colspan="7" class="py-1.5 pr-4">Opening balance</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($ledger['opening_balance'], 2) }}</td>
                        </tr>
                    @endif
                    @forelse($ledger['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                            <td class="py-1.5 pr-4">{{ $row['num'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['description'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['transfer'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['reconciled'] }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-1.5 px-2 text-gray-400">No posted transactions in this range.</td></tr>
                    @endforelse
                    <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                        <td colspan="7" class="py-2 pr-4">Closing balance</td>
                        <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($ledger['closing_balance'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
