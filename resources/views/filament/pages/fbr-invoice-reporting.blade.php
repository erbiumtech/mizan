<x-filament-panels::page>
    @php($reconciliation = $this->reconciliation())
    @php($enabled = $reconciliation->enabled())
    @php($findings = $reconciliation->findings())

    <x-filament::section>
        <x-slot name="heading">Reporting status</x-slot>
        <x-slot name="description">
            Digital invoicing reports each sales-tax invoice to FBR as it is issued.
        </x-slot>

        @if($enabled)
            <p class="text-sm text-gray-700 dark:text-gray-200">
                Reporting is <strong>on</strong> for this company. Every issued sale invoice is expected
                to carry an FBR reference; anything below is a gap between the books and FBR.
            </p>
        @else
            <p class="text-sm text-gray-700 dark:text-gray-200">
                Reporting is <strong>off</strong> for this company, so no invoice is expected to carry an
                FBR reference and nothing here is overdue.
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Turn it on only once the company is registered and its integrator testing is complete.
                Whether it is <em>required</em> depends on sales-tax registration and turnover — that is a
                question for the company's tax advisor, not for this screen.
            </p>
        @endif
    </x-filament::section>

    @if(empty($findings))
        <x-filament::section>
            <x-slot name="heading">Nothing to reconcile</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if($enabled)
                    Every issued invoice is accounted for at FBR.
                @else
                    Nothing is being reported, so there is nothing to compare.
                @endif
            </p>
        </x-filament::section>
    @endif

    @foreach($findings as $key => $group)
        <x-filament::section>
            <x-slot name="heading">
                {{ $group['label'] }}
                <span class="text-gray-400 font-normal">({{ $group['invoices']->count() }})</span>
            </x-slot>
            <x-slot name="description">{{ $group['explanation'] }}</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">Invoice</th>
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 font-medium">Customer</th>
                            <th class="py-2 pr-4 font-medium">FBR status</th>
                            <th class="py-2 pr-4 font-medium">Reference</th>
                            <th class="py-2 pl-4 font-medium text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['invoices'] as $invoice)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $invoice->invoice_number }}</td>
                                <td class="py-1.5 pr-4">{{ $invoice->invoice_date?->toDateString() }}</td>
                                <td class="py-1.5 pr-4">{{ $invoice->contact?->name }}</td>
                                <td class="py-1.5 pr-4">{{ str($invoice->fbr_status ?? 'not_required')->replace('_', ' ')->title() }}</td>
                                <td class="py-1.5 pr-4 text-gray-400">{{ $invoice->fbr_irn ?: '—' }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format((float) $invoice->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
