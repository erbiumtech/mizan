<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())
    @php($money = fn ($amount) => number_format((float) $amount, 2))

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-reports.stat-card
            label="Foreign accounts"
            :value="count($report['rows'])"
            help="Accounts holding a currency other than the base one"
        />
        <x-reports.stat-card
            label="Net adjustment"
            :value="$money($report['net'])"
            :color="$report['net'] > 0 ? 'success' : ($report['net'] < 0 ? 'danger' : 'gray')"
            :help="$report['net'] > 0 ? 'An unrealised gain' : ($report['net'] < 0 ? 'An unrealised loss' : 'Nothing has moved')"
        />
        <x-reports.stat-card
            label="As at"
            :value="\Carbon\Carbon::parse($report['as_of'])->format('d M Y')"
            help="The rate in force on this date"
        />
    </div>

    @if($report['problems'] !== [])
        <x-filament::section>
            <x-slot name="heading">These accounts could not be revalued</x-slot>
            <x-slot name="description">
                They are left exactly as they are. A balance skipped for want of a rate is the one that then
                goes unnoticed, so it is named here rather than passed over.
            </x-slot>

            <ul class="space-y-1 text-sm text-danger-600 dark:text-danger-400">
                @foreach($report['problems'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">What each foreign balance is worth on this date</x-slot>
        <x-slot name="description">
            The account holds the foreign amount, and that does not change. What changes is the base-currency
            figure the balance sheet reports: each posting was translated on its own day, so the balance is a
            sum of historical rates until it is restated at one.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">Account</th>
                        <th class="py-2 pr-4 text-right font-medium">Holds</th>
                        <th class="py-2 pr-4 text-right font-medium">Rate</th>
                        <th class="py-2 pr-4 text-right font-medium">In the books</th>
                        <th class="py-2 pr-4 text-right font-medium">Worth on the date</th>
                        <th class="py-2 pl-4 text-right font-medium">Adjustment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">
                                <span class="font-mono text-xs text-gray-400">{{ $row['code'] }}</span>
                                {{ $row['name'] }}
                            </td>
                            <td class="py-1.5 pr-4 text-right tabular-nums">
                                {{ $row['currency_code'] }} {{ $money($row['foreign_balance']) }}
                            </td>
                            <td class="py-1.5 pr-4 text-right tabular-nums text-gray-500">
                                {{ number_format($row['rate'], 4) }}
                            </td>
                            <td class="py-1.5 pr-4 text-right tabular-nums text-gray-500">{{ $money($row['base_balance']) }}</td>
                            <td class="py-1.5 pr-4 text-right tabular-nums">{{ $money($row['translated']) }}</td>
                            <td @class([
                                'py-1.5 pl-4 text-right tabular-nums font-medium',
                                'text-success-600 dark:text-success-400' => $row['adjustment'] > 0,
                                'text-danger-600 dark:text-danger-400' => $row['adjustment'] < 0,
                                'text-gray-400' => abs($row['adjustment']) < 0.005,
                            ])>
                                {{ abs($row['adjustment']) < 0.005 ? '—' : $money($row['adjustment']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-400">
                                No account is denominated in another currency. Set one on an account — a EUR bank
                                account, say — and its balance is revalued here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($report['rows'] !== [])
            <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
                The adjustment goes to {{ \App\Modules\Accounting\Services\CurrencyRevaluationService::UNREALISED_ACCOUNT_CODE }}
                Unrealised Exchange Gain / (Loss), apart from realised differences: no money has moved and none
                will until the balance is settled. Posting again after this changes nothing unless a rate or a
                transaction has.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
