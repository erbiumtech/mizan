<x-filament-panels::page>
    {{ $this->form }}

    @php
        $rows = $this->rows();
        $totals = $this->totals();
        $missing = $this->missing();
        $locked = $this->lockedCount();
        $money = fn ($value) => number_format((float) $value, 2);
    @endphp

    @if ($rows->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No work-in-progress position has been computed for this month. Use <strong>Recompute every job</strong>
                above.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="The month">
            <x-slot name="description">
                {{-- Saying which is which matters more than either number: a figure a bank has seen and a figure that
                     will change by Friday look identical on paper. --}}
                {{ $locked }} of {{ $rows->count() }} position(s) are locked. Locked positions are frozen as they were
                signed off; the rest recompute from today's forecast, measurements and certificates.
            </x-slot>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ([
                    'Cost to date' => $money($totals['cost_to_date']),
                    'Contract value' => $money($totals['contract_value']),
                    'Revenue recognised' => $money($totals['revenue_recognised']),
                    'Billed to date' => $money($totals['billings_to_date']),
                    'Contract asset' => $money($totals['contract_asset']),
                    'Contract liability' => $money($totals['contract_liability']),
                ] as $label => $value)
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-lg font-semibold tabular-nums">{{ $value }}</p>
                    </div>
                @endforeach

                {{-- Beside the contract value and never in it. A job whose contract value looks comfortable while
                     eleven million of variations sit unapproved is a job about to be in trouble. --}}
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Variations pending</p>
                    <p @class([
                        'text-lg font-semibold tabular-nums',
                        'text-warning-600 dark:text-warning-400' => $totals['variations_pending'] > 0,
                    ])>{{ $money($totals['variations_pending']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Excluded from contract value</p>
                </div>

                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Provision for loss</p>
                    <p @class([
                        'text-lg font-semibold tabular-nums',
                        'text-danger-600 dark:text-danger-400' => $totals['provision_for_loss'] > 0,
                    ])>{{ $money($totals['provision_for_loss']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Whole, never spread</p>
                </div>
            </div>

            @if ($totals['contract_asset'] > 0 && $totals['contract_liability'] > 0)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    Both positions are non-zero across the portfolio, which is normal — they are opposite sides of the
                    balance sheet and are never netted against each other. One job over-billed does not offset another
                    under-billed.
                </p>
            @endif

            @if ($totals['provision_for_loss'] > 0)
                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">
                    {{ $money($totals['provision_for_loss']) }} of expected loss is recognised in full this month, not
                    spread across the months remaining. A loss pro-rated is how a loss-making contract reports as
                    profitable until the month it finishes.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="By job">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1 pr-4">Job</th>
                            <th class="py-1 pr-4">Method</th>
                            <th class="py-1 pr-4 text-right">Complete</th>
                            <th class="py-1 pr-4 text-right">Cost</th>
                            <th class="py-1 pr-4 text-right">Forecast final</th>
                            <th class="py-1 pr-4 text-right">Contract value</th>
                            <th class="py-1 pr-4 text-right">Pending VOs</th>
                            <th class="py-1 pr-4 text-right">Revenue</th>
                            <th class="py-1 pr-4 text-right">Billed</th>
                            <th class="py-1 pr-4 text-right">Loss</th>
                            <th class="py-1 pr-4 text-right">Asset</th>
                            <th class="py-1 pr-4 text-right">Liability</th>
                            <th class="py-1">State</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-1 pr-4">{{ $row->job?->code }} — {{ $row->job?->name }}</td>
                                {{-- The method beside the percentage, always. 62% cost-to-cost is not the same claim as
                                     62% surveyed, and a bank asking which will not accept "the system said so". --}}
                                <td class="py-1 pr-4">{{ $row->methodLabel() }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ number_format((float) $row->percent_complete, 2) }}%</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->totalCost()) }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->forecast_final_cost) }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->contract_value) }}</td>
                                <td @class(['py-1 pr-4 text-right tabular-nums', 'text-warning-600 dark:text-warning-400' => (float) $row->variations_pending > 0])>
                                    {{ $money($row->variations_pending) }}
                                </td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->revenue_recognised) }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->billings_to_date) }}</td>
                                <td @class(['py-1 pr-4 text-right tabular-nums', 'text-danger-600 dark:text-danger-400' => $row->isLossMaking()])>
                                    {{ $money($row->provision_for_loss) }}
                                </td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->contract_asset) }}</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ $money($row->contract_liability) }}</td>
                                <td class="py-1">
                                    @if ($row->isPosted())
                                        <span class="text-success-600 dark:text-success-400">Posted</span>
                                    @elseif ($row->isLocked())
                                        <span class="text-gray-500 dark:text-gray-400">Locked</span>
                                    @else
                                        <span class="text-warning-600 dark:text-warning-400">Live</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Exactly one of asset and liability is non-zero per job. A contract asset is work earned and not yet
                billed; a contract liability is money billed and not yet earned.
            </p>
        </x-filament::section>
    @endif

    @if ($missing !== [])
        {{-- Named rather than left out: a WIP report missing a job is a balance sheet missing a contract, and the
             commonest reason is a two-second fix somebody has to be told about. --}}
        <x-filament::section heading="Live jobs with no position this month">
            <ul class="space-y-1 text-sm text-warning-600 dark:text-warning-400">
                @foreach ($missing as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
