<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())
    @php($money = fn ($amount) => number_format((float) $amount, 2))

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-reports.stat-card
            label="Assets"
            :value="$money($report['assets']['total'])"
            color="primary"
        />
        <x-reports.stat-card
            label="Liabilities"
            :value="$money($report['liabilities']['total'])"
            color="danger"
        />
        <x-reports.stat-card
            label="Equity"
            :value="$money($report['equity_total'])"
            :color="$report['equity_total'] < 0 ? 'danger' : 'success'"
            :help="'includes ' . $money($report['retained_earnings_for_period']) . ' earned this period'"
        />
    </div>

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

        {{-- A statement can balance and still be half-migrated: unbalanced opening
             balances collect in Opening Balance Equity instead of showing as an
             imbalance, so it is called out separately. --}}
        @if(($obe = $report['opening_balance_equity'] ?? null) && ! $obe['is_clear'])
            <p class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-gray-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-gray-200">
                Account {{ $obe['code'] }} (Opening Balance Equity) still holds
                <span class="font-medium tabular-nums">{{ $money($obe['balance']) }}</span>.
                Every opening balance credits this account, so a leftover means some accounts' opening
                figures have not been entered — this statement can balance and still be incomplete.
            </p>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">Code</th>
                        <th class="py-2 pr-4 font-medium">Account</th>
                        <th class="py-2 pl-4 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach([['Assets', $report['assets']], ['Liabilities', $report['liabilities']]] as [$heading, $section])
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <td colspan="3" class="px-2 py-2 text-xs font-semibold uppercase tracking-wide">{{ $heading }}</td>
                        </tr>
                        @forelse($section['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $row['code'] }}</td>
                                <td class="py-1.5 pr-4">{{ $row['name'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td colspan="3" class="py-1.5 text-gray-400">Nothing recorded</td>
                            </tr>
                        @endforelse
                        <tr class="border-b border-gray-200 font-medium dark:border-white/10">
                            <td colspan="2" class="py-1.5 pr-4">Total {{ strtolower($heading) }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($section['total']) }}</td>
                        </tr>
                    @endforeach

                    <tr class="bg-gray-50 dark:bg-white/5">
                        <td colspan="3" class="px-2 py-2 text-xs font-semibold uppercase tracking-wide">Equity</td>
                    </tr>
                    @foreach($report['equity']['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">{{ $row['code'] }}</td>
                            <td class="py-1.5 pr-4">{{ $row['name'] }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($row['amount']) }}</td>
                        </tr>
                    @endforeach

                    {{-- Its own line, because it is in no account yet: income and
                         expense accounts are zeroed into Retained Earnings only at
                         year-end, so between closes the profit so far sits in them. --}}
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-1.5 pr-4"></td>
                        <td class="py-1.5 pr-4">
                            Earnings for the period, not yet closed
                            <span class="text-gray-400">— matches the Profit &amp; Loss to this date</span>
                        </td>
                        <td class="py-1.5 pl-4 text-right tabular-nums {{ $report['retained_earnings_for_period'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                            {{ $money($report['retained_earnings_for_period']) }}
                        </td>
                    </tr>
                    <tr class="border-b border-gray-200 font-medium dark:border-white/10">
                        <td colspan="2" class="py-1.5 pr-4">Total equity</td>
                        <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($report['equity_total']) }}</td>
                    </tr>

                    <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                        <td colspan="2" class="py-2 pr-4">Liabilities and equity</td>
                        <td class="py-2 pl-4 text-right tabular-nums {{ $report['balanced'] ? '' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ $money($report['liabilities_and_equity_total']) }}
                        </td>
                    </tr>
                    <tr class="font-semibold">
                        <td colspan="2" class="py-2 pr-4">Total assets</td>
                        <td class="py-2 pl-4 text-right tabular-nums {{ $report['balanced'] ? '' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ $money($report['assets']['total']) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        @unless($report['balanced'])
            <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                Assets do not equal liabilities plus equity. This statement is derived from the trial balance,
                so the two cannot disagree with each other — an imbalance here means the ledger itself is out,
                and the trial balance for the same date will say so too.
            </p>
        @endunless
    </x-filament::section>
</x-filament-panels::page>
