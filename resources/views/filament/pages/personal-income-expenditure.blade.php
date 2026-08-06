@php($report = $this->getReport())

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Earned</p>
                <p class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">
                    PKR {{ number_format($report['total_income'], 2) }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Spent</p>
                <p class="mt-1 text-2xl font-semibold text-warning-600 dark:text-warning-400">
                    PKR {{ number_format($report['total_expenses'], 2) }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ $report['surplus'] >= 0 ? 'Left over' : 'Overspent by' }}
                </p>
                <p @class([
                    'mt-1 text-2xl font-bold',
                    'text-success-600 dark:text-success-400' => $report['surplus'] >= 0,
                    'text-danger-600 dark:text-danger-400' => $report['surplus'] < 0,
                ])>
                    PKR {{ number_format(abs($report['surplus']), 2) }}
                </p>
            </div>
        </div>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([
            ['heading' => 'What came in', 'rows' => $report['income'], 'total' => $report['total_income'], 'empty' => 'No income recorded for this year.'],
            ['heading' => 'What went out', 'rows' => $report['expenses'], 'total' => $report['total_expenses'], 'empty' => 'No spending recorded for this year.'],
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
                                        {{ number_format($row['amount'], 2) }}
                                    </td>
                                    <td class="w-16 py-2 text-right text-xs text-gray-400 dark:text-gray-500">
                                        @if ($section['total'] > 0)
                                            {{ number_format($row['amount'] / $section['total'] * 100, 1) }}%
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <td class="pt-3 font-semibold text-gray-950 dark:text-white">Total</td>
                                <td class="pt-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                    {{ number_format($section['total'], 2) }}
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
