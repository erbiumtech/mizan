<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())
    @php($money = fn ($amount) => number_format((float) $amount, 2))
    @php($signed = fn ($amount) => ((float) $amount) < 0 ? 'text-danger-600 dark:text-danger-400' : '')

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-reports.stat-card label="Opening cash" :value="$money($report['opening_cash'])" />
        <x-reports.stat-card
            label="Net change"
            :value="$money($report['net_change'])"
            :color="$report['net_change'] < 0 ? 'danger' : 'success'"
        />
        <x-reports.stat-card label="Closing cash" :value="$money($report['closing_cash'])" color="primary" />
    </div>

    <x-filament::section>
        <x-slot name="heading">
            {{ $report['from'] ? \Carbon\Carbon::parse($report['from'])->format('d M Y') : 'Start of the book' }}
            to {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}
        </x-slot>
        <x-slot name="afterHeader">
            @if($report['reconciles'])
                <x-filament::badge color="success">Reconciles</x-filament::badge>
            @else
                <x-filament::badge color="danger">Does not reconcile</x-filament::badge>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <tbody>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <td colspan="2" class="px-2 py-2 text-xs font-semibold uppercase tracking-wide">Operating</td>
                    </tr>
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-1.5 pr-4">
                            Net income
                            <span class="text-gray-400">— from the Profit &amp; Loss for the same period</span>
                        </td>
                        <td class="py-1.5 pl-4 text-right tabular-nums {{ $signed($report['operating']['net_income']) }}">
                            {{ $money($report['operating']['net_income']) }}
                        </td>
                    </tr>
                    @if($report['operating']['depreciation'] != 0)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">
                                Depreciation
                                <span class="text-gray-400">— added back; it costs no cash</span>
                            </td>
                            <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($report['operating']['depreciation']) }}</td>
                        </tr>
                    @endif
                    @foreach($report['operating']['working_capital'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">{{ $row['code'] }} {{ $row['name'] }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums {{ $signed($row['amount']) }}">{{ $money($row['amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-b border-gray-200 font-medium dark:border-white/10">
                        <td class="py-1.5 pr-4">Cash from operating</td>
                        <td class="py-1.5 pl-4 text-right tabular-nums {{ $signed($report['operating']['total']) }}">
                            {{ $money($report['operating']['total']) }}
                        </td>
                    </tr>

                    @foreach([['Investing', $report['investing']], ['Financing', $report['financing']]] as [$heading, $section])
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <td colspan="2" class="px-2 py-2 text-xs font-semibold uppercase tracking-wide">{{ $heading }}</td>
                        </tr>
                        @forelse($section['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $row['code'] }} {{ $row['name'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums {{ $signed($row['amount']) }}">{{ $money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td colspan="2" class="py-1.5 text-gray-400">No {{ strtolower($heading) }} activity</td>
                            </tr>
                        @endforelse
                        <tr class="border-b border-gray-200 font-medium dark:border-white/10">
                            <td class="py-1.5 pr-4">Cash from {{ strtolower($heading) }}</td>
                            <td class="py-1.5 pl-4 text-right tabular-nums {{ $signed($section['total']) }}">{{ $money($section['total']) }}</td>
                        </tr>
                    @endforeach

                    <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                        <td class="py-2 pr-4">Net change in cash</td>
                        <td class="py-2 pl-4 text-right tabular-nums {{ $signed($report['net_change']) }}">{{ $money($report['net_change']) }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 pr-4 text-gray-500">Opening cash</td>
                        <td class="py-1.5 pl-4 text-right tabular-nums text-gray-500">{{ $money($report['opening_cash']) }}</td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">Closing cash</td>
                        <td class="py-2 pl-4 text-right tabular-nums">{{ $money($report['closing_cash']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @unless($report['reconciles'])
            <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                The three sections do not add up to the change in cash, which can only happen if an account
                escaped classification. Every non-cash account belongs to exactly one section by construction,
                so this is a bug rather than a data problem.
            </p>
        @endunless
    </x-filament::section>
</x-filament-panels::page>
