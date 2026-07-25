<x-filament-panels::page>
    {{ $this->form }}

    @php($fiscalYear = $this->fiscalYear())
    @php($payments = $this->getPayments())
    @php($month = $this->data['month'] ?? null)

    <x-filament::section>
        <x-slot name="heading">Salary Bank File (iPayments CSV)</x-slot>
        <x-slot name="description">
            Standard Chartered iPayments bulk-payment export — UTF-8, comma-delimited
            @if($fiscalYear) &middot; Fiscal year {{ $fiscalYear->name }} @endif
        </x-slot>

        @if(! $payments)
            <p class="text-gray-400 text-sm">No payslips found for {{ $month }}.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">#</th>
                            <th class="py-2 pr-4 font-medium">Employee</th>
                            <th class="py-2 pr-4 font-medium">Account / IBAN</th>
                            <th class="py-2 pr-4 font-medium">Bank</th>
                            <th class="py-2 pr-4 font-medium">Details</th>
                            <th class="py-2 pl-4 font-medium text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payments as $i => $p)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $i + 1 }}</td>
                                <td class="py-1.5 pr-4">{{ $p['name'] }}</td>
                                <td class="py-1.5 pr-4">
                                    @if($p['account'])
                                        {{ $p['account'] }}
                                    @else
                                        <x-filament::badge color="danger">missing</x-filament::badge>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-4">{{ $p['bank_name'] ?: '—' }}</td>
                                <td class="py-1.5 pr-4">{{ $p['details'] }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($p['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="5" class="py-2 pr-4">Total ({{ count($payments) }} payments)</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format(collect($payments)->sum('amount'), 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if(collect($payments)->contains(fn ($p) => ! $p['account']))
                <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                    Some employees have no bank account/IBAN on file — their rows will export with a blank account. Fill these in on the Employee record before uploading.
                </p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
