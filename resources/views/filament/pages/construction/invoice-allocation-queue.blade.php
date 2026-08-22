<x-filament-panels::page>
    @php
        $invoices = $this->invoices();
        $money = fn (?float $value) => $value === null ? '—' : number_format($value, 2);
    @endphp

    {{--
        Empty is a result rather than a blank page. §4.2 asks for the unallocated section to be "rendered even when
        empty" for exactly this reason: a screen that looks broken when it is finished is a screen people stop
        opening, and this queue only works if somebody opens it.
    --}}
    @if ($invoices->isEmpty())
        <x-filament::section heading="Nothing awaiting allocation">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Every purchase invoice is attributed to a job and a cost code. That is worth seeing rather than
                assuming: an unallocated invoice leaves the accounts perfectly correct and the job under-costed, so
                nothing else on any report would have told you.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-baseline justify-between gap-4">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Unallocated, in total</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ $money($this->queueTotal()) }}</p>
                </div>
                <p class="max-w-xl text-sm text-gray-500 dark:text-gray-400">
                    Cost that has reached the accounts and no job. Until it is allocated, every one of these jobs is
                    reporting a margin better than it has.
                </p>
            </div>
        </x-filament::section>

        @foreach ($invoices as $invoice)
            @php
                $unallocated = $this->unallocatedOn($invoice);
                $allocations = $this->allocationsOn($invoice);
                $age = $this->ageInDays($invoice);
            @endphp

            <x-filament::section
                :heading="$invoice->invoice_number.' — '.($invoice->contact->name ?? 'No supplier')"
                collapsible
            >
                <x-slot name="description">
                    {{ $invoice->invoice_date?->format('d M Y') }}
                    @if ($age !== null)
                        · {{ $age }} {{ \Illuminate\Support\Str::plural('day', $age) }} old
                    @endif
                    · net {{ $money((float) $invoice->subtotal) }}
                </x-slot>

                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Still unallocated</p>
                        <p class="text-lg font-semibold tabular-nums text-warning-600 dark:text-warning-400">
                            {{ $money($unallocated) }}
                        </p>
                    </div>

                    {{ ($this->allocateAction)(['invoice' => $invoice->getKey()]) }}
                </div>

                @if ($invoice->lines->isNotEmpty())
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4">What the supplier billed</th>
                                    <th class="py-2 pr-4 text-right">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invoice->lines as $line)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <td class="py-2 pr-4">{{ $line->description }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">
                                            {{ $money((float) $line->line_total) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($allocations !== [])
                    <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Allocated so far
                    </h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4">Job</th>
                                    <th class="py-2 pr-4">Cost code</th>
                                    <th class="py-2 pr-4">Order</th>
                                    <th class="py-2 pr-4 text-right">Amount</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($allocations as $allocation)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <td class="py-2 pr-4">{{ $allocation->job?->code }}</td>
                                        <td class="py-2 pr-4 font-mono">{{ $allocation->costCode?->code }}</td>
                                        <td class="py-2 pr-4">
                                            {{-- "Unordered" rather than a blank: it is a fact about the invoice. --}}
                                            {{ $allocation->commitmentLine?->commitment?->number ?? 'Unordered' }}
                                        </td>
                                        <td @class([
                                            'py-2 pr-4 text-right tabular-nums',
                                            'text-success-600 dark:text-success-400' => $allocation->isCredit(),
                                        ])>{{ $money((float) $allocation->amount) }}</td>
                                        <td class="py-2 text-right">
                                            {{ ($this->withdrawAction)(['allocation' => $allocation->getKey()]) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
