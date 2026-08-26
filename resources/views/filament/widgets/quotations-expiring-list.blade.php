{{--
    Quotations expiring inside a fortnight — reports-expansion-plan.md Phase 5.3.

    A custom view rather than a TableWidget, for the same reason as the debtors list: the rows come from a
    service call and not from a query builder this widget owns. See
    App\Modules\Quotations\Filament\Widgets\QuotationsExpiringList.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Quotations expiring soon</x-slot>

        <x-slot name="description">
            Within {{ \App\Modules\Quotations\Filament\Widgets\QuotationsExpiringList::DAYS }} days of
            {{ \Illuminate\Support\Carbon::parse($this->from())->format('j M Y') }}
        </x-slot>

        @forelse ($this->quotations() as $quotation)
            <div class="fi-debtor-row">
                <span class="fi-debtor-name">{{ $quotation['number'] }} · {{ $quotation['party'] }}</span>

                <span class="fi-debtor-meta">
                    {{-- Nought days left is today, which is the most urgent of the set rather than a
                         rounding of "expired". --}}
                    {{ $quotation['days'] === 0 ? 'lapses today' : 'lapses in '.$quotation['days'].' days' }}
                    · valid until {{ \Illuminate\Support\Carbon::parse($quotation['until'])->format('j M') }}
                </span>

                <span class="fi-debtor-amount">
                    {{ \App\Support\Reporting\ReportFigures::money($quotation['total']) }}
                </span>
            </div>
        @empty
            <p class="fi-debtor-empty">No quotation lapses in the next
                {{ \App\Modules\Quotations\Filament\Widgets\QuotationsExpiringList::DAYS }} days.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
