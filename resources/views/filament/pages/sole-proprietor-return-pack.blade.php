@php($pack = $this->getPack())

<x-filament-panels::page>
    <x-filament::section heading="Worksheet" description="Business profit taxed on your individual slabs. The statement below computes from what this form shows — Save keeps it for the year.">
        {{ $this->form }}
    </x-filament::section>

    @if ($pack)
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Taxable income</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">PKR {{ number_format($pack['taxable_income'], 2) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Tax due (individual slabs)</p>
                    <p class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">PKR {{ number_format($pack['tax_due'], 2) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        {{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable' }}
                    </p>
                    <p class="mt-1 text-2xl font-bold {{ $pack['balance'] >= 0 ? 'text-primary-600 dark:text-primary-400' : 'text-success-600 dark:text-success-400' }}">
                        PKR {{ number_format(abs($pack['balance']), 2) }}
                    </p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="From accounting profit to tax due">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Revenue</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($pack['pnl']['income']['total'], 2) }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Expenses</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">({{ number_format($pack['pnl']['expenses']['total'], 2) }})</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">Accounting profit per the ledger</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['pnl']['net_profit'], 2) }}</td>
                        </tr>
                        @foreach ($pack['worksheet']['adjustments'] as $adjustment)
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $adjustment['label'] !== '' ? $adjustment['label'] : 'Adjustment' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">
                                    {{ $adjustment['amount'] < 0 ? '(' . number_format(abs($adjustment['amount']), 2) . ')' : number_format($adjustment['amount'], 2) }}
                                </td>
                            </tr>
                        @endforeach
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">Taxable income</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['taxable_income'], 2) }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">
                                Tax on individual slabs{{ $pack['tax']['bracket'] ? ' — ' . $pack['tax']['bracket']->label() : '' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($pack['tax']['tax'], 2) }}</td>
                        </tr>
                        @if (($pack['tax']['surcharge'] ?? 0) > 0)
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Add: surcharge</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($pack['tax']['surcharge'], 2) }}</td>
                            </tr>
                        @endif
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">Tax due</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['tax_due'], 2) }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Less: advance income tax suffered (account 1260)</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">({{ number_format($pack['tax_paid'], 2) }})</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">{{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable' }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format(abs($pack['balance']), 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Prepared for filing, not filed</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-gray-400">
            <li>A sole proprietor files an individual return (114(1)); this is the business-income head, taxed on your individual slabs. Any salary, rental or other personal income is entered on the return alongside it.</li>
            <li>Minimum tax (s.113) for individuals is not yet computed here — a high-turnover year may owe it; confirm with your practitioner.</li>
            <li>The adjustments are yours to keep true — tax vs accounting depreciation, inadmissible expenses, exempt income. Advance tax is the in-year movement of account 1260.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
