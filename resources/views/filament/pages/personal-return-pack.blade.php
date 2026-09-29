@php($result = $this->getPack())
@php($pack = $result['pack'])

<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($result['error'])
        {{-- Shown rather than swallowed — "no schedule seeded" and "you owe
             nothing" have to look different. Same rule as the estimate. --}}
        <x-filament::section>
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                This cannot be assembled yet
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $result['error'] }}</p>
        </x-filament::section>
    @elseif ($pack)
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Tax chargeable</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        PKR {{ number_format($pack['income']['total_payable'], 2) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Already paid or withheld</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        PKR {{ number_format($pack['tax_paid'], 2) }}
                    </p>
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
            @if ($result['filer_status'])
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    Recorded as {{ $result['filer_status'] === 'filer' ? 'a filer' : 'a non-filer' }} for this year.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Income and tax — the 114(1) figures">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 font-medium">Head of income</th>
                            <th class="px-3 py-2 text-right font-medium">Gross</th>
                            <th class="px-3 py-2 text-right font-medium">Allowance</th>
                            <th class="px-3 py-2 text-right font-medium">Taxable</th>
                            <th class="px-3 py-2 text-right font-medium">Tax</th>
                            <th class="px-3 py-2 text-right font-medium">Surcharge</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pack['income']['regimes'] as $row)
                            <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['income'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ ($row['allowance'] ?? 0) > 0 ? '(' . number_format($row['allowance'], 2) . ')' : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['taxable'], 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($row['tax'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                    {{ ($row['surcharge'] ?? 0) > 0 ? number_format($row['surcharge'], 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($pack['income']['unclassified'] > 0)
                <p class="mt-3 text-sm font-medium text-warning-600 dark:text-warning-400">
                    PKR {{ number_format($pack['income']['unclassified'], 2) }} of income has no
                    <strong>Taxed as</strong> setting and is not assessed above — it does appear in the
                    wealth reconciliation, so the pack does not balance it away. Classify it under
                    Chart of Accounts before filing.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Wealth statement — assets and liabilities at both ends of the year">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 font-medium">Account</th>
                            <th class="px-3 py-2 text-right font-medium">Opening</th>
                            <th class="px-3 py-2 text-right font-medium">Closing</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities'] as $side => $label)
                            @if ($pack['wealth'][$side] !== [])
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <td colspan="3" class="px-3 py-2 text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $label }}</td>
                                </tr>
                                @foreach ($pack['wealth'][$side] as $row)
                                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['code'] }} — {{ $row['name'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['opening'], 2) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['closing'], 2) }}</td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                        <tr>
                            <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">Net assets</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['wealth']['opening_net'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['wealth']['closing_net'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Reconciliation of net assets">
            @php($rec = $pack['reconciliation'])
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">Net assets at the start of the year</dt><dd class="tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($rec['opening_net'], 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">Add: income recorded this year</dt><dd class="tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($rec['inflows'], 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">Less: personal expenses this year</dt><dd class="tabular-nums text-gray-700 dark:text-gray-200">({{ number_format($rec['expenses'], 2) }})</dd></div>
                <div class="flex justify-between border-t border-gray-200 pt-1 dark:border-white/10"><dt class="text-gray-600 dark:text-gray-300">Net assets that should result</dt><dd class="tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($rec['expected_closing'], 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">Net assets the books actually show</dt><dd class="tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($rec['actual_closing'], 2) }}</dd></div>
                <div class="flex justify-between font-semibold {{ abs($rec['unexplained']) < 0.005 ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400' }}">
                    <dt>Unexplained difference</dt>
                    <dd class="tabular-nums">{{ number_format($rec['unexplained'], 2) }}</dd>
                </div>
            </dl>
            @if (abs($rec['unexplained']) >= 0.005)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    Wealth moved outside the income and expense accounts — an opening balance set during the
                    year, a gift, or a posting that never happened. This is the figure a return gets asked
                    about, so explain it before filing rather than after.
                </p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section>
        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
            Prepared for filing, not filed
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-gray-400">
            <li>FBR has no filing API: enter these figures at iris.fbr.gov.pk yourself, or hand the PDF to your tax practitioner.</li>
            <li>Tax already paid is the in-year movement of account 1600 (Advance &amp; Withheld Tax) — record withholding there or the balance overstates what you owe.</li>
            <li>Everything the tax estimate does not know — credits, receipted deductions, holding-period capital gains rates — this pack does not know either.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
