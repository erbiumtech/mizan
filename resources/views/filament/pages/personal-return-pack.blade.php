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
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Tax chargeable (9200)</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        PKR {{ number_format($pack['tax_chargeable'], 2) }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ number_format($pack['normal_tax'], 2) }} on slabs + {{ number_format($pack['final_tax'], 2) }} fixed/final
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Withholding income tax (9201)</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        PKR {{ number_format($pack['tax_paid'], 2) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        {{ $pack['balance'] >= 0 ? 'Admitted income tax (9203)' : 'Refundable income tax' }}
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

        <x-filament::section heading="Income by head" description="The four columns IRIS uses; final-regime income never joins taxable income.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 text-right font-medium">Total income</th>
                            <th class="px-3 py-2 text-right font-medium">Subject to final tax</th>
                            <th class="px-3 py-2 text-right font-medium">Subject to exemption</th>
                            <th class="px-3 py-2 text-right font-medium">Subject to normal tax</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pack['heads'] as $head)
                            <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $head['label'] }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400">{{ $head['code'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($head['total'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($head['final'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($head['exempt'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($head['normal'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($pack['income']['unclassified'] > 0)
                <p class="mt-3 text-sm font-medium text-warning-600 dark:text-warning-400">
                    PKR {{ number_format($pack['income']['unclassified'], 2) }} of income has no
                    <strong>Taxed as</strong> setting and is not assessed above — it does appear in the
                    reconciliation, so the pack does not balance it away. Classify it under Chart of
                    Accounts before filing.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Computations" description="The 9000-series, in the order the return prints them.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($pack['computations'] as $row)
                            <tr class="border-b border-gray-200 last:border-0 dark:border-white/10 {{ in_array($row['code'], ['9200', '9203'], true) ? 'font-semibold' : '' }}">
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400">{{ $row['code'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($pack['withholding_by_section'] !== [])
            <x-filament::section heading="Withholding by section" description="The 9201 total broken out the way IRIS itemises it.">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2 font-medium">Section</th>
                                <th class="px-3 py-2 font-medium">Account</th>
                                <th class="px-3 py-2 text-right font-medium">Tax withheld</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pack['withholding_by_section'] as $row)
                                <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['section'] }}</td>
                                    <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400">{{ $row['code'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['tax'], 2) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">Total (9201)</td>
                                <td></td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['tax_paid'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="How the slab tax was worked out" collapsible collapsed>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 font-medium">Taxed as</th>
                            <th class="px-3 py-2 text-right font-medium">Income</th>
                            <th class="px-3 py-2 text-right font-medium">Taxable</th>
                            <th class="px-3 py-2 font-medium">Bracket</th>
                            <th class="px-3 py-2 text-right font-medium">Tax</th>
                            <th class="px-3 py-2 text-right font-medium">Treatment</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pack['income']['regimes'] as $row)
                            <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['label'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['income'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['taxable'], 2) }}</td>
                                <td class="px-3 py-2 text-xs whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $row['bracket']?->label() ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($row['total'], 2) }}</td>
                                <td class="px-3 py-2 text-right text-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Support\TaxRegimes::isFinal($row['regime']) ? 'Final' : 'Normal' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Wealth statement" description="Each account beside the IRIS 7000-code it declares under.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-2 font-medium">Account</th>
                            <th class="px-3 py-2 font-medium">IRIS</th>
                            <th class="px-3 py-2 text-right font-medium">Opening</th>
                            <th class="px-3 py-2 text-right font-medium">Closing</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities'] as $side => $label)
                            @if ($pack['wealth'][$side] !== [])
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <td colspan="4" class="px-3 py-2 text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $label }}</td>
                                </tr>
                                @foreach ($pack['wealth'][$side] as $row)
                                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                                        <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['code'] }} — {{ $row['name'] }}</td>
                                        <td class="px-3 py-2 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $row['iris_code'] }} {{ $row['iris_label'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['opening'], 2) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ number_format($row['closing'], 2) }}</td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                        <tr>
                            <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">Net assets (703001)</td>
                            <td></td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['wealth']['opening_net'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($pack['wealth']['closing_net'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Reconciliation of net assets" description="The 703-series; unreconciled (703000) is the figure a return gets asked about.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($pack['reconciliation'] as $row)
                            <tr class="border-b border-gray-200 last:border-0 dark:border-white/10 {{ $row['code'] === '703000' ? (abs($row['amount']) < 0.005 ? 'font-semibold text-success-600 dark:text-success-400' : 'font-semibold text-warning-600 dark:text-warning-400') : '' }}">
                                <td class="px-3 py-2 {{ in_array($row['code'], ['7031', '7033', '7089'], true) ? 'pl-8 text-gray-500 dark:text-gray-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $row['label'] }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400">{{ $row['code'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (abs($pack['unreconciled']) >= 0.005)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    Wealth moved outside the income and expense accounts — an opening balance set during the
                    year, a gift, or a posting that never happened. Explain it before filing rather than after.
                </p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section>
        <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
            Prepared for filing, not filed
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-500 dark:text-gray-400">
            <li>FBR has no filing API: enter these figures at iris.fbr.gov.pk against the codes shown, or hand the PDF to your tax practitioner.</li>
            <li>Withholding (9201) is the in-year movement of the tax-withheld accounts (1600, and 1601–1604 by section) — record what banks and employers withhold there, or 9203 overstates what you owe. Post to the section account (1601–1604) to see the per-section breakdown IRIS itemises; anything general stays on 1600.</li>
            <li>Everything the tax estimate does not know — credits, receipted deductions, holding-period capital gains rates, exempt income — this pack does not know either. The Exemption column is zeros until an exempt regime exists.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
