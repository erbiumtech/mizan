<x-filament-panels::page>
    {{ $this->form }}

    @php
        $statement = $this->statement();
        $latest = $this->latestRun();
        $history = $this->history();
        $money = fn (float $value) => number_format($value, 2);
    @endphp

    @if ($statement->scopeWarning)
        {{-- No cost control account nominated. §17.6's rule about a rate with no denominator applies here: the
             difference would be the whole of job cost, which reads as a catastrophe and means nobody has set the
             module up. Say what is missing instead of printing a number that reads as a finding. --}}
        <x-filament::section heading="Nothing to reconcile against">
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $statement->scopeWarning }}</p>
        </x-filament::section>
    @else
        {{-- §4.2's statement, printed as the plan writes it: one line per step, the difference at the foot. --}}
        <x-filament::section heading="The statement">
            <x-slot name="description">
                Computed live. Posted journal entries only, dated inside the month — the same filter the financial
                reports use, because two sides disagreeing about drafts is a difference nobody can explain.
            </x-slot>

            <div class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                <div class="flex items-baseline justify-between py-2">
                    <span>General ledger cost on the control accounts</span>
                    <span class="font-medium tabular-nums">{{ $money($statement->glCost) }}</span>
                </div>

                <div class="flex items-baseline justify-between py-2">
                    <span>
                        less cost carrying no job
                        {{-- "Shown, never spread." Apportioning this across jobs would balance the report and put
                             money on jobs nobody charged it to. --}}
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Subtracted whole and never apportioned across jobs
                        </span>
                    </span>
                    <span class="font-medium tabular-nums">({{ $money($statement->unallocatedGlCost) }})</span>
                </div>

                <div class="flex items-baseline justify-between py-2">
                    <span>
                        plus job cost still awaiting the general ledger
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            On the job, not yet in the books
                        </span>
                    </span>
                    <span class="font-medium tabular-nums">{{ $money($statement->pendingJobCost) }}</span>
                </div>

                <div class="flex items-baseline justify-between py-2 font-semibold">
                    <span>Expected job-cost total</span>
                    <span class="tabular-nums">{{ $money($statement->expectedJobCost) }}</span>
                </div>

                <div class="flex items-baseline justify-between py-2">
                    <span>Job cost recorded (everything except memo)</span>
                    <span class="font-medium tabular-nums">{{ $money($statement->jobCost) }}</span>
                </div>

                <div class="flex items-baseline justify-between py-3">
                    <span class="text-base font-semibold">Difference</span>
                    <span @class([
                        'text-xl font-semibold tabular-nums',
                        'text-success-600 dark:text-success-400' => $statement->isBalanced(),
                        'text-danger-600 dark:text-danger-400' => ! $statement->isBalanced(),
                    ])>{{ $money($statement->difference) }}</span>
                </div>
            </div>

            <p @class([
                'mt-3 text-sm',
                'text-success-600 dark:text-success-400' => $statement->isBalanced(),
                'text-danger-600 dark:text-danger-400' => ! $statement->isBalanced(),
            ])>{{ $statement->describe() }}</p>
        </x-filament::section>

        {{-- §4.2: the drill-down by cause "is what makes it a tool rather than a number". --}}
        <x-filament::section heading="By cause">
            <x-slot name="description">
                A difference with no cause attached is a figure somebody screenshots and argues about. Sections with a
                figure against them are what is not reconciling; the rest are checks worth having looked at.
            </x-slot>

            <div class="space-y-5">
                @foreach ($statement->causes as $cause)
                    @if ($cause->shouldRender())
                        <div>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-medium">
                                    {{ $cause->label }}
                                    @if ($cause->count > 0)
                                        <span class="text-gray-500 dark:text-gray-400">
                                            — {{ number_format($cause->count) }}
                                        </span>
                                    @endif
                                </p>
                                @if (abs($cause->amount) >= 0.01)
                                    <span class="text-sm font-semibold tabular-nums text-danger-600 dark:text-danger-400">
                                        {{ $money($cause->amount) }}
                                    </span>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $cause->explanation }}</p>

                            @if ($cause->rows !== [])
                                <div class="mt-2 overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                            @foreach ($cause->rows as $row)
                                                <tr>
                                                    @foreach ($row as $key => $value)
                                                        <td @class([
                                                            'py-1 pr-4',
                                                            'text-right tabular-nums' => is_numeric($value),
                                                        ])>
                                                            @if (is_float($value) || $key === 'amount' || $key === 'total')
                                                                {{ number_format((float) $value, 2) }}
                                                            @else
                                                                {{ $value }}
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- The stored runs. A period balanced every month that broke in March is a different problem from one nobody has
         ever proved, and only a series of rows can say which. --}}
    <x-filament::section heading="Runs recorded" :collapsible="true" :collapsed="$history->isEmpty()">
        @if ($history->isEmpty())
            <p class="text-sm text-warning-600 dark:text-warning-400">
                This month has never been reconciled. A second ledger that nobody proves is a second ledger that is
                wrong — record a run, or let the weekly <code>construction:reconcile</code> do it.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1 pr-4">Run</th>
                            <th class="py-1 pr-4">Status</th>
                            <th class="py-1 pr-4 text-right">Difference</th>
                            <th class="py-1">Reason given</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($history as $run)
                            <tr>
                                <td class="py-1 pr-4">{{ $run->run_at->format('d M Y H:i') }}</td>
                                <td class="py-1 pr-4">
                                    <span @class([
                                        'text-success-600 dark:text-success-400' => $run->isBalanced(),
                                        'text-danger-600 dark:text-danger-400' => $run->blocksClose(),
                                        'text-warning-600 dark:text-warning-400' => $run->isAccepted(),
                                    ])>{{ $run->statusLabel() }}</span>
                                </td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ number_format((float) $run->difference, 2) }}</td>
                                <td class="py-1">{{ $run->accepted_reason ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($latest?->isAccepted())
                {{-- Saying this plainly matters: an accepted difference is not a fixed one, and somebody reading the
                     word "accepted" should not conclude it has been dealt with. --}}
                <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">
                    The difference on this month has been accepted, which corrected nothing. Both ledgers stand as they
                    are and the difference is still there until its cause is fixed.
                </p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
