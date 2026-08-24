{{--
    The five largest debtors — reports-expansion-plan.md Phase 5.5.

    A custom view rather than a TableWidget because the rows are an aggregate over
    `InvoiceService::outstandingReceivables()` — the same service the Aged Receivables report reads — and not
    an Eloquent query. See App\Modules\Invoicing\Filament\Widgets\LargestDebtorsList.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Largest debtors</x-slot>

        <x-slot name="description">
            Outstanding as at {{ \Illuminate\Support\Carbon::parse($this->asOf())->format('j M Y') }}
        </x-slot>

        @php($debtors = $this->debtors())

        @forelse ($debtors as $debtor)
            <div class="fi-debtor-row">
                <span class="fi-debtor-name">{{ $debtor['contact'] }}</span>

                <span class="fi-debtor-meta">
                    {{-- The worst of their invoices, not an average: a customer with one invoice ninety days
                         late and nine current ones is a ninety-day problem. --}}
                    {{ $debtor['days'] > 0 ? $debtor['days'].' days overdue' : 'within terms' }}
                    ·
                    {{ $debtor['invoices'] }} {{ \Illuminate\Support\Str::plural('invoice', $debtor['invoices']) }}
                </span>

                <span class="fi-debtor-amount">
                    {{ \App\Support\Reporting\ReportFigures::money($debtor['outstanding']) }}
                </span>
            </div>
        @empty
            {{-- Not "no data". Nobody owing anything is a fact worth stating plainly on a dashboard. --}}
            <p class="fi-debtor-empty">Nobody owes anything at this date.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
