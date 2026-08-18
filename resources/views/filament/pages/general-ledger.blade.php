<x-filament-panels::page>
    {{ $this->form }}

    @php($ledgers = $this->getLedgers())
    @php($totals = $this->getTotals())

    <x-filament::section>
        <x-slot name="heading">
            {{ count($ledgers) }} {{ \Illuminate\Support\Str::plural('account', count($ledgers)) }}
        </x-slot>
        <x-slot name="afterHeader">
            @if ($totals['balanced'])
                <x-filament::badge color="success">Balanced</x-filament::badge>
            @else
                <x-filament::badge color="danger">Out of balance</x-filament::badge>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                        <th class="py-2 pr-4 font-medium">Date</th>
                        <th class="py-2 pr-4 font-medium">Ref</th>
                        <th class="py-2 pr-4 font-medium">Description</th>
                        <th class="py-2 pl-4 font-medium text-right">Debit</th>
                        <th class="py-2 pl-4 font-medium text-right">Credit</th>
                        <th class="py-2 pl-4 font-medium text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ledgers as $ledger)
                        {{--
                            The opening balance belongs in the account's heading, not in a row of its own:
                            it is a figure carried in from the period before, and listing it as a line
                            would invite it to be added to the period's own debits.
                        --}}
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <td colspan="5" class="py-2 px-2 font-semibold text-xs uppercase tracking-wide">
                                {{ $ledger['account']['code'] }} {{ $ledger['account']['name'] }}
                            </td>
                            <td class="py-2 px-2 text-right text-xs tabular-nums">
                                Opening {{ number_format($ledger['opening_balance'], 2) }}
                            </td>
                        </tr>

                        @foreach ($ledger['lines'] as $line)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4 whitespace-nowrap">{{ $line['date'] }}</td>
                                <td class="py-1.5 pr-4 whitespace-nowrap">{{ $line['entry_number'] }}</td>
                                <td class="py-1.5 pr-4">{{ $line['memo'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $line['debit'] ? number_format($line['debit'], 2) : '' }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $line['credit'] ? number_format($line['credit'], 2) : '' }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($line['balance'], 2) }}</td>
                            </tr>
                        @endforeach

                        <tr class="border-b-2 border-gray-200 dark:border-white/10 font-medium">
                            <td colspan="5" class="py-1.5 pr-4 text-right">Closing balance</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($ledger['closing_balance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Nothing has been posted in this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($ledgers !== [])
                    <tfoot>
                        <tr class="font-semibold">
                            <td colspan="3" class="py-2 pr-4">Total for the period</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($totals['debits'], 2) }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($totals['credits'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
