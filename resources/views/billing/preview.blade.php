{{--
    What the month's bill contains, before an invoice is raised from it.

    Grouped the way the client reads it: who was employed, what the office cost,
    and what came back off the advances.
--}}
@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $sections = [
        ['Employees', $breakdown['salaries'], $breakdown['salary_total']],
        ['Office expenses', $breakdown['expenses'], $breakdown['expense_total']],
        ['Credits', $breakdown['credits'], $breakdown['credit_total']],
    ];
@endphp

<div class="space-y-6 text-sm">
    @foreach ($sections as [$heading, $lines, $total])
        <div>
            <h3 class="font-semibold text-gray-950 dark:text-white">
                {{ $heading }}
                <span class="font-normal text-gray-500">({{ count($lines) }})</span>
            </h3>

            @if (count($lines) === 0)
                <p class="mt-1 text-gray-500 dark:text-gray-400">Nothing this month.</p>
            @else
                <table class="mt-2 w-full">
                    <tbody>
                        @foreach ($lines as $line)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1 pr-4 text-gray-700 dark:text-gray-300">{{ $line['description'] }}</td>
                                <td class="py-1 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ $money($line['amount']) }}
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="pt-1 pr-4 text-right font-medium text-gray-500">Subtotal</td>
                            <td class="pt-1 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ $money($total) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <div class="border-t border-gray-200 pt-4 dark:border-white/10">
        <div class="flex items-baseline justify-between">
            <span class="font-semibold text-gray-950 dark:text-white">Total to bill</span>
            <span class="text-lg font-bold tabular-nums text-gray-950 dark:text-white">
                {{ $money($breakdown['subtotal']) }}
            </span>
        </div>

        @if ((float) $run->exchange_rate > 0)
            <div class="mt-1 flex items-baseline justify-between text-gray-500 dark:text-gray-400">
                <span>At {{ number_format((float) $run->exchange_rate, 2) }} per {{ $run->currency }}</span>
                <span class="tabular-nums">
                    {{ $run->currency }} {{ $money($breakdown['subtotal'] / (float) $run->exchange_rate) }}
                </span>
            </div>
        @else
            <p class="mt-1 text-gray-500 dark:text-gray-400">
                Set a rate on the bill to see the {{ $run->currency }} figure.
            </p>
        @endif
    </div>
</div>
