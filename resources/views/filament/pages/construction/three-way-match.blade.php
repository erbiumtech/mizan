<x-filament-panels::page>
    {{ $this->form }}

    @php
        $rows = $this->rows();
        $money = fn (?float $value) => $value === null ? '—' : number_format($value, 2);
        $qty = fn (?float $value) => $value === null
            ? '—'
            : (rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0');
    @endphp

    <x-filament::section>
        <div class="flex flex-wrap items-baseline justify-between gap-6">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Variance on the report</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $money($this->total()) }}</p>
            </div>

            {{--
                The thresholds, printed. A report whose threshold is invisible is one people argue with rather than
                act on: "why is this on here" should not need somebody to open a settings screen to answer.
            --}}
            <div class="flex flex-wrap gap-4">
                @foreach ($this->tolerances() as $label => $value)
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-sm font-medium tabular-nums">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    @if ($rows === [])
        {{--
            Empty is a result. Ordered, received and invoiced agreeing is the whole point of the control, and a blank
            page would read as a screen that had not loaded.
        --}}
        <x-filament::section heading="Everything agrees">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Ordered, received and invoiced match on every open order line, within the tolerances above. Nothing
                needs a decision.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="Where the three disagree">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">Order</th>
                            <th class="py-2 pr-4">Job</th>
                            <th class="py-2 pr-4">Description</th>
                            <th class="py-2 pr-4 text-right">Ordered</th>
                            <th class="py-2 pr-4 text-right">Received</th>
                            <th class="py-2 pr-4 text-right">Invoiced</th>
                            <th class="py-2 pr-4 text-right">Qty variance</th>
                            <th class="py-2 pr-4 text-right">Price variance</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4">{{ $row['line']->commitment?->number }}</td>
                                <td class="py-2 pr-4">{{ $row['line']->job?->code }}</td>
                                <td class="py-2 pr-4">
                                    {{ str($row['line']->description)->limit(48) }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['line']->costCode?->code }}
                                        @if ($row['note'])
                                            {{-- The reason a figure is missing travels with the absence, never a zero. --}}
                                            · {{ $row['note'] }}
                                        @endif
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ $money($row['ordered_amount']) }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ $qty($row['ordered_quantity']) }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ $money($row['received_amount']) }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ $qty($row['received_quantity']) }}
                                    </span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ $money($row['invoiced_amount']) }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        {{ $qty($row['invoiced_quantity']) }}
                                    </span>
                                </td>
                                <td @class([
                                    'py-2 pr-4 text-right tabular-nums',
                                    'text-danger-600 dark:text-danger-400' => ($row['quantity_variance'] ?? 0) != 0,
                                ])>{{ $qty($row['quantity_variance']) }}</td>
                                <td @class([
                                    'py-2 pr-4 text-right tabular-nums',
                                    'text-danger-600 dark:text-danger-400' => $row['price_variance'] != 0,
                                ])>{{ $money($row['price_variance']) }}</td>
                                <td class="py-2 text-right">
                                    {{ ($this->acceptAction)(['line' => $row['line']->getKey()]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Nothing here is blocked. A variance that stopped an invoice being paid is how a site ends up with a
                supplier refusing the next delivery over money nobody could authorise — so the control is the
                decision, with a name and a reason on it.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
