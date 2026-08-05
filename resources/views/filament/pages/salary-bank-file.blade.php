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
                            <th class="py-2 pr-4 font-medium">In this batch</th>
                            <th class="py-2 pl-4 font-medium text-right">Net Salary</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payments as $i => $p)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $i + 1 }}</td>
                                <td class="py-1.5 pr-4">{{ $p['name'] }}</td>
                                <td class="py-1.5 pr-4">
                                    @if($p['account_problem'] ?? null)
                                        {{ $p['account'] ? $p['account'] : '' }}
                                        <x-filament::badge color="danger" size="xs">
                                            {{ \App\Modules\Accounting\Support\BankFileAccount::problemLabel($p['account_problem']) }}
                                        </x-filament::badge>
                                    @elseif($p['account'])
                                        {{ $p['account'] }}
                                        {{-- Which identifier the file will carry: SCB accounts go by
                                             account number, everyone else by IBAN. Worth showing on a
                                             file that moves money. --}}
                                        @if(($p['account_kind'] ?? '') === 'account_no')
                                            <x-filament::badge color="info" size="xs">A/C</x-filament::badge>
                                        @elseif(($p['account_kind'] ?? '') === 'iban')
                                            <x-filament::badge color="gray" size="xs">IBAN</x-filament::badge>
                                        @endif
                                    @else
                                        <x-filament::badge color="danger">missing</x-filament::badge>
                                    @endif
                                </td>
                                {{-- The short code, because that is what column 66 of the file
                                     carries. Banks with no short code on record export blank, so
                                     they are flagged rather than shown as an empty cell. --}}
                                <td class="py-1.5 pr-4">
                                    @if($p['bank_short_code'])
                                        {{ $p['bank_short_code'] }}
                                    @elseif($p['bank_name'])
                                        <x-filament::badge color="warning" size="xs">no short code</x-filament::badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-1.5 pr-4">{{ $p['details'] }}</td>

                                {{-- A held-back row stays visible with its reason. Dropping it
                                     from the list would leave someone wondering why the total
                                     is short, which is how an unpaid salary goes unnoticed. --}}
                                <td class="py-1.5 pr-4">
                                    @if($p['releasable'])
                                        <x-filament::badge color="success" size="xs">Ready</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning" size="xs">Held</x-filament::badge>
                                        <span class="text-xs text-gray-500">{{ $p['blocked_reason'] }}</span>
                                    @endif
                                </td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($p['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                        @php($ready = collect($payments)->where('releasable', true))
                        @php($held = collect($payments)->where('releasable', false))

                        {{-- The total is what the file will carry, not what the month adds up
                             to: the two differ whenever a payslip is still unaccepted, and a
                             total that ignored that would not reconcile against the bank. --}}
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="6" class="py-2 pr-4">This batch ({{ $ready->count() }} of {{ count($payments) }} payments)</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($ready->sum('amount'), 2) }}</td>
                        </tr>

                        {{-- Grouped by *why*, not lumped under one reason. This line used to read
                             "Held back until accepted" whatever the reason, so a month whose
                             salaries had already gone out in an earlier batch was described as
                             waiting for an acceptance that had nothing to do with it — and sent
                             somebody looking for a screen to accept them on. --}}
                        @foreach($held->groupBy('blocked_category') as $category => $rows)
                            <tr class="text-warning-600 dark:text-warning-400">
                                <td colspan="6" class="py-2 pr-4">
                                    {{ \App\Modules\Accounting\Models\Payment::blockLabel($category) }}
                                    ({{ $rows->count() }})
                                </td>
                                <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($rows->sum('amount'), 2) }}</td>
                            </tr>
                        @endforeach

                        @if($held->isNotEmpty())
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <td colspan="7" class="pb-2 pr-4">
                                    Held rows are listed above with their own reason. Already-released ones are
                                    not waiting for anything — void the batch if that file has to be re-issued.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @php($noAccount = collect($payments)->where('account_problem', \App\Modules\Accounting\Support\BankFileAccount::PROBLEM_NO_ACCOUNT))
            @php($wrongKind = collect($payments)->where('account_problem', \App\Modules\Accounting\Support\BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY))

            @if($noAccount->isNotEmpty())
                <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                    {{ $noAccount->count() }} employee(s) have no bank account or IBAN on file — those rows would
                    export with a blank account, which the bank rejects. Fill them in on the Employee record.
                </p>
            @endif

            @if($wrongKind->isNotEmpty())
                {{-- The quiet one. The row looks complete and the file looks valid, and the
                     payment is rejected or misdirected days later — so these are held back
                     rather than warned about. --}}
                <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                    {{ $wrongKind->count() }} employee(s) bank with us and have only an IBAN on file. A transfer
                    inside our own bank is sent on the account number, so these are held back until it is added
                    on the Employee record — an IBAN here would look valid and fail at the bank.
                </p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
