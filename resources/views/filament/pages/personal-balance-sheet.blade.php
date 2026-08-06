@php($report = $this->getReport())

<x-filament-panels::page>
    <x-filament::section>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">What you own</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    PKR {{ number_format($report['total_assets'], 2) }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">What you owe</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    PKR {{ number_format($report['total_liabilities'], 2) }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Net worth</p>
                {{-- Negative net worth is shown as what it is rather than hidden. --}}
                <p @class([
                    'mt-1 text-2xl font-bold',
                    'text-success-600 dark:text-success-400' => $report['net_worth'] >= 0,
                    'text-danger-600 dark:text-danger-400' => $report['net_worth'] < 0,
                ])>
                    PKR {{ number_format($report['net_worth'], 2) }}
                </p>
            </div>
        </div>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([
            ['heading' => 'What you own', 'rows' => $report['assets'], 'total' => $report['total_assets'], 'empty' => 'No assets recorded yet.'],
            ['heading' => 'What you owe', 'rows' => $report['liabilities'], 'total' => $report['total_liabilities'], 'empty' => 'Nothing owed — or nothing recorded yet.'],
        ] as $section)
            <x-filament::section :heading="$section['heading']">
                @if ($section['rows'] === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $section['empty'] }}</p>
                @else
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($section['rows'] as $row)
                                <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                    <td class="py-2">
                                        <span class="text-gray-400 dark:text-gray-500">{{ $row['code'] }}</span>
                                        <span class="ml-2 text-gray-700 dark:text-gray-200">{{ $row['name'] }}</span>
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                        {{ number_format($row['balance'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <td class="pt-3 font-semibold text-gray-950 dark:text-white">Total</td>
                                <td class="pt-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                    {{ number_format($section['total'], 2) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Your own records only. Nobody else sees these, and they are separate from the company's accounts.
    </p>
</x-filament-panels::page>
