<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())

    <x-filament::section>
        <x-slot name="heading">
            As of {{ \Carbon\Carbon::parse($report['as_of'])->format('d M Y') }}
        </x-slot>
        <x-slot name="afterHeader">
            @if($report['balanced'])
                <x-filament::badge color="success">Balanced</x-filament::badge>
            @else
                <x-filament::badge color="danger">Out of balance</x-filament::badge>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                        <th class="py-2 pr-4 font-medium">Code</th>
                        <th class="py-2 pr-4 font-medium">Account</th>
                        <th class="py-2 pl-4 font-medium text-right">Debit</th>
                        <th class="py-2 pl-4 font-medium text-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['sections'] as $section)
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <td colspan="4" class="py-2 px-2 font-semibold uppercase text-xs tracking-wide">{{ $section['type'] }}</td>
                        </tr>
                        @foreach($section['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $row['code'] }}</td>
                                <td class="py-1.5 pr-4">{{ $row['name'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-b border-gray-200 dark:border-white/10 font-medium">
                            <td colspan="2" class="py-1.5 pr-4">Total {{ $section['type'] }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($section['total_debits'], 2) }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($section['total_credits'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold {{ $report['balanced'] ? '' : 'text-danger-600 dark:text-danger-400' }}">
                        <td colspan="2" class="py-2 pr-4">Grand Total</td>
                        <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($report['total_debits'], 2) }}</td>
                        <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($report['total_credits'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
