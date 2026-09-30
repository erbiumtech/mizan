@php($pack = $this->getPack())

<x-filament-panels::page>
    <x-filament::section heading="Worksheet" description="The statement below always computes from what this form shows — Save keeps it for the year.">
        {{ $this->form }}
    </x-filament::section>

    @if ($pack)
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Accounting profit</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">PKR {{ number_format($pack['pnl']['net_profit'], 2) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Taxable income</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">PKR {{ number_format($pack['taxable_income'], 2) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        Tax due — {{ $pack['basis'] === 'minimum' ? 'minimum (s.113)' : 'normal' }}
                    </p>
                    <p class="mt-1 text-2xl font-bold text-primary-600 dark:text-primary-400">PKR {{ number_format($pack['tax_due'], 2) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        {{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable / carry forward' }}
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
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Revenue (turnover)</td>
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
                        @if ($pack['loss_applied'] > 0)
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">Less: brought-forward loss set off</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">({{ number_format($pack['loss_applied'], 2) }})</td>
                            </tr>
                        @endif
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">Taxable income</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['taxable_income'], 2) }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10 {{ $pack['basis'] === 'normal' ? 'font-semibold' : '' }}">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Normal tax at {{ rtrim(rtrim(number_format($pack['worksheet']['tax_rate'], 2), '0'), '.') }}%</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($pack['normal_tax'], 2) }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10 {{ $pack['basis'] === 'minimum' ? 'font-semibold' : '' }}">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Minimum tax at {{ rtrim(rtrim(number_format($pack['worksheet']['minimum_tax_rate'], 2), '0'), '.') }}% of turnover (s.113)</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($pack['minimum_tax'], 2) }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">
                                Tax on income — the greater of normal and minimum
                                @if ($pack['super_tax'] > 0), before super tax @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format(max($pack['normal_tax'], $pack['minimum_tax']), 2) }}</td>
                        </tr>
                        @if ($pack['super_tax'] > 0)
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">Add: super tax (s.4C)</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($pack['super_tax'], 2) }}</td>
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
                            <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">{{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable / carry forward' }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format(abs($pack['balance']), 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="The profit and loss behind it" collapsible collapsed>
            <div class="grid gap-6 sm:grid-cols-2">
                @foreach (['income' => 'Income', 'expenses' => 'Expenses'] as $side => $label)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                                    <th class="px-3 py-2 font-medium">{{ $label }}</th>
                                    <th class="px-3 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pack['pnl'][$side]['rows'] as $row)
                                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['code'] }} — {{ $row['name'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['amount'], 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">Total</td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['pnl'][$side]['total'], 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
            Prepared for filing, not filed
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-gray-400">
            <li>FBR has no filing API: enter these figures at iris.fbr.gov.pk, or hand the PDF to your tax practitioner.</li>
            <li>The adjustments are yours to keep true — tax depreciation, inadmissible expenses and exempt income live in the Ordinance, not in the ledger.</li>
            <li>Super tax (section 4C) is deliberately not computed: its slabs move yearly. Add it as an adjustment-informed figure with your practitioner if income crosses the threshold.</li>
            <li>Advance tax suffered is the in-year movement of account 1260 — record customer withholding there or the balance overstates what is owed.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
