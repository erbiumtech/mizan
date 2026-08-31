{{--
    One receipt across several invoices — docs/erpnext-gap-plan.md Phase 2, item 2.

    A screen rather than a document type: every box below ends in `InvoiceService::recordPayment()`, the
    settlement path that already exists, so nothing here posts anything of its own. What the screen adds is
    the arithmetic somebody was doing on paper — one transfer, several invoices, and the figure that has to
    reach zero before it can be recorded.
--}}
<x-filament-panels::page>
    <div class="fi-receipt">
        <div class="fi-receipt-controls">
            <label class="fi-explorer-date">
                <span class="fi-sr-only">Customer</span>
                <select wire:model.live="contactId" class="fi-explorer-date-input">
                    <option value="">Choose a customer</option>
                    @foreach ($this->customers() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="fi-explorer-date">
                <span class="fi-sr-only">Received on</span>
                <input type="date" wire:model.live="receivedOn" class="fi-explorer-date-input">
            </label>

            <label class="fi-explorer-date">
                <span class="fi-sr-only">Amount received</span>
                <input type="number" step="0.01" min="0" wire:model.live="amount" placeholder="Amount received" class="fi-explorer-date-input">
            </label>

            <label class="fi-explorer-date">
                <span class="fi-sr-only">Bank reference</span>
                <input type="text" wire:model.blur="reference" placeholder="Bank reference" class="fi-explorer-date-input">
            </label>

            <button type="button" wire:click="allocateOldestFirst" class="fi-explorer-open">Allocate oldest first</button>
        </div>

        @if (blank($this->contactId))
            <p class="fi-explorer-empty">Choose a customer to see what they owe.</p>
        @else
            @php($invoices = $this->openInvoices())

            @if ($invoices->isEmpty())
                <p class="fi-explorer-empty">This customer has nothing outstanding.</p>
            @else
                <div class="fi-explorer-statement fi-explorer-table">
                    <div class="fi-explorer-statement-head" style="grid-template-columns: minmax(0, 1fr) 8rem 8rem 9rem 9rem">
                        <span>Invoice</span>
                        <span>Date</span>
                        <span>Due</span>
                        <span class="fi-num">Outstanding</span>
                        <span class="fi-num">Allocate</span>
                    </div>

                    <div class="fi-explorer-statement-body">
                        @foreach ($invoices as $invoice)
                            <div class="fi-explorer-line" style="grid-template-columns: minmax(0, 1fr) 8rem 8rem 9rem 9rem" wire:key="inv-{{ $invoice->getKey() }}">
                                <span class="fi-explorer-line-label">{{ $invoice->invoice_number }}</span>
                                <span>{{ $invoice->invoice_date?->format('j M Y') }}</span>
                                <span>{{ $invoice->due_date?->format('j M Y') ?? '—' }}</span>
                                <span class="fi-num">{{ number_format((float) $invoice->outstanding(), 2) }}</span>
                                <span class="fi-num">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="{{ $invoice->outstanding() }}"
                                        wire:model.live.debounce.400ms="allocations.{{ $invoice->getKey() }}"
                                        class="fi-receipt-input"
                                        aria-label="Allocate to {{ $invoice->invoice_number }}"
                                    >
                                </span>
                            </div>
                        @endforeach

                        {{--
                            The figure that decides whether this can be recorded at all. Shown rather than
                            checked on submit, because a receipt that will be refused should look refusable
                            before somebody presses the button.
                        --}}
                        <div class="fi-explorer-total fi-explorer-closing" style="grid-template-columns: minmax(0, 1fr) 8rem 8rem 9rem 9rem">
                            <span class="fi-explorer-line-label" style="grid-column: span 3">
                                Allocated {{ number_format($this->allocated(), 2) }} of {{ number_format((float) $this->amount, 2) }}
                            </span>
                            <span class="fi-num">Unallocated</span>
                            <span @class(['fi-num', 'fi-warn' => abs($this->unallocated()) >= 0.01])>
                                {{ number_format($this->unallocated(), 2) }}
                            </span>
                        </div>
                    </div>

                    <div class="fi-explorer-statement-foot">
                        THIS APPLICATION HAS NOWHERE TO HOLD MONEY THAT IS NOT AGAINST AN INVOICE — THE
                        UNALLOCATED FIGURE HAS TO BE NIL
                    </div>
                </div>

                <div class="fi-receipt-actions">
                    <button
                        type="button"
                        wire:click="record"
                        @disabled(abs($this->unallocated()) >= 0.01 || $this->allocated() < 0.01)
                        class="fi-explorer-open fi-explorer-open-lg"
                    >Record receipt</button>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
