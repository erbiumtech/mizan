<x-filament-panels::page>
    {{ $this->form }}

    @php($fiscalYear = $this->fiscalYear())
    @php($rows = $this->getRows())

    <x-filament::section>
        <x-slot name="heading">Bank Payment File (iPayments CSV)</x-slot>
        <x-slot name="description">
            Salaries, rent, food and other payments in one iPayments file
            @if($fiscalYear) &middot; Fiscal year {{ $fiscalYear->name }} @endif
        </x-slot>

        @if($rows->isEmpty())
            <p class="text-gray-400 text-sm">No pending (draft/approved) payments match. Salary payments are generated automatically from payslips; add rent/food payments on the Payments screen.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">#</th>
                            <th class="py-2 pr-4 font-medium">Payee</th>
                            <th class="py-2 pr-4 font-medium">Type</th>
                            <th class="py-2 pr-4 font-medium">Payment Type</th>
                            <th class="py-2 pr-4 font-medium">Account / IBAN</th>
                            <th class="py-2 pr-4 font-medium">Details</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 pl-4 font-medium text-right">Net Salary</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows->groupBy(fn ($p) => $p->transactionType->name) as $typeName => $group)
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <td colspan="8" class="py-2 px-2 font-semibold uppercase text-xs tracking-wide">{{ $typeName }}</td>
                            </tr>
                            @foreach($group as $p)
                                @php($b = $p->beneficiaryDetails())
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-4">{{ $p->id }}</td>
                                    <td class="py-1.5 pr-4">{{ $b['name'] }}</td>
                                    <td class="py-1.5 pr-4">{{ $p->transactionType->name }}</td>
                                    <td class="py-1.5 pr-4"><x-filament::badge color="success">{{ $p->resolvedPaymentType() }}</x-filament::badge></td>
                                    <td class="py-1.5 pr-4">
                                        @if($b['account'])
                                            {{ $b['account'] }}
                                        @else
                                            <x-filament::badge color="danger">missing</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="py-1.5 pr-4">{{ $p->details }}</td>
                                    <td class="py-1.5 pr-4">{{ $p->status }}</td>
                                    <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($p->amount, 2) }}</td>
                                </tr>
                            @endforeach
                            <tr class="border-b border-gray-200 dark:border-white/10 font-medium">
                                <td colspan="7" class="py-1.5 pr-4">Total {{ $typeName }} ({{ $group->count() }})</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($group->sum('amount'), 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="7" class="py-2 pr-4">Grand Total ({{ $rows->count() }} payments)</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($rows->sum('amount'), 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if($rows->contains(fn ($p) => ! $p->beneficiaryDetails()['account']))
                <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">Some payees have no bank account/IBAN on file — fill these in before uploading.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
