<x-filament-panels::page>
    {{ $this->form }}

    @php($report = $this->getReport())
    @php($money = fn ($amount) => number_format((float) $amount, 2))

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-reports.stat-card label="Contractors paid" :value="count($report['contractors'])" />
        <x-reports.stat-card label="Total paid" :value="$money($report['total'])" color="primary" />
        <x-reports.stat-card
            label="Period"
            :value="$report['fiscal_year'] ?? '—'"
            :help="\Carbon\Carbon::parse($report['from'])->format('d M Y') . ' – ' . \Carbon\Carbon::parse($report['to'])->format('d M Y')"
        />
    </div>

    <x-filament::section>
        <x-slot name="heading">People paid for work who are not on the payroll</x-slot>
        <x-slot name="description">
            Money that actually went out — released or paid. Drafts and approvals are intentions, not receipts.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">Contractor</th>
                        <th class="py-2 pr-4 font-medium">NTN / CNIC</th>
                        <th class="py-2 pr-4 font-medium">Payments</th>
                        <th class="py-2 pr-4 font-medium">Last paid</th>
                        <th class="py-2 pl-4 text-right font-medium">Paid</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['contractors'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-4">
                                {{ $row['name'] }}
                                @if($row['engagement'])
                                    <span class="block text-xs text-gray-400">{{ $row['engagement'] }}</span>
                                @endif
                            </td>
                            <td class="py-1.5 pr-4 font-mono text-xs">{{ $row['tax_identity'] ?: '—' }}</td>
                            <td class="py-1.5 pr-4 text-gray-500">{{ $row['payments'] }}</td>
                            <td class="py-1.5 pr-4">
                                {{ $row['last_paid_on'] ? \Carbon\Carbon::parse($row['last_paid_on'])->format('d M Y') : '—' }}
                            </td>
                            <td class="py-1.5 pl-4 text-right tabular-nums font-medium">{{ $money($row['paid']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-gray-400">
                                No contractor was paid in this period. Mark a beneficiary as a contractor to have them
                                counted here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($report['contractors'] !== [])
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                            <td colspan="4" class="py-2 pr-4">Total</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ $money($report['total']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            No tax is withheld from these payments. A contractor invoices for their work and what they owe on it is
            theirs to settle — withholding from them would be treating them as staff, which is the mistake this
            distinction exists to avoid.
        </p>
    </x-filament::section>
</x-filament-panels::page>
