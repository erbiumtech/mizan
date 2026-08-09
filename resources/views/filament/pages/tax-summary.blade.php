<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())
    @php($money = fn ($amount) => number_format((float) $amount, 2))

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-reports.stat-card label="Taxable earnings" :value="$money($report['taxable_total'])" />
        <x-reports.stat-card label="Tax withheld" :value="$money($report['tax_total'])" color="warning" />
        <x-reports.stat-card
            label="Employees"
            :value="count($report['employees'])"
            :help="$report['month'] ? $report['month'] . ' ' . $report['fiscal_year'] : $report['fiscal_year']"
        />
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">By employee</x-slot>
            <x-slot name="description">Most withheld first. Section 149/4, as filed.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Employee</th>
                            <th class="py-2 pr-4 font-medium">Months</th>
                            <th class="py-2 pl-4 text-right font-medium">Taxable</th>
                            <th class="py-2 pl-4 text-right font-medium">Tax</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['employees'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">
                                    {{ $row['name'] }}
                                    @if($row['nic'])
                                        <span class="block text-xs text-gray-400">{{ $row['nic'] }}</span>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-4 text-gray-500">{{ $row['months'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($row['taxable']) }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums font-medium">{{ $money($row['tax']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-gray-400">
                                    No tax withheld in this period — every payslip fell below the threshold.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">By month</x-slot>
            <x-slot name="description">In fiscal-year order — what was remitted, and when.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Month</th>
                            <th class="py-2 pr-4 font-medium">Employees</th>
                            <th class="py-2 pl-4 text-right font-medium">Taxable</th>
                            <th class="py-2 pl-4 text-right font-medium">Tax</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['months'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $row['month'] }}</td>
                                <td class="py-1.5 pr-4 text-gray-500">{{ $row['employees'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ $money($row['taxable']) }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums font-medium">{{ $money($row['tax']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">Nothing to report.</td></tr>
                        @endforelse
                    </tbody>
                    @if($report['months'] !== [])
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                                <td colspan="2" class="py-2 pr-4">Total</td>
                                <td class="py-2 pl-4 text-right tabular-nums">{{ $money($report['taxable_total']) }}</td>
                                <td class="py-2 pl-4 text-right tabular-nums">{{ $money($report['tax_total']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Salary withholding under section 149 only. Sales tax is not covered — invoices carry a single
        tax figure tied to no rate, so there is nothing yet to report on.
    </p>
</x-filament-panels::page>
