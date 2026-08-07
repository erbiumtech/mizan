<x-filament-panels::page>
    {{ $this->form }}

    @php($account = $this->currentAccount())
    @php($ledger = $this->getLedger())
    @php($from = $this->data['from'] ?? null)
    @php($rows = collect($ledger['rows']))
    @php($totalDebit = $rows->sum('debit'))
    @php($totalCredit = $rows->sum('credit'))
    {{-- Computed once: Action::toHtml() renders regardless of visible(), so each
         button has to be gated here. --}}
    @php($canEditRow = $this->editRowAction->isVisible())
    @php($canDeleteRow = $this->deleteRowAction->isVisible())
    @php($canReverseRow = $this->reverseRowAction->isVisible())

    {{-- Summary stat cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-reports.stat-card
            label="Opening Balance"
            :value="number_format($ledger['opening_balance'], 2)"
            :color="$ledger['opening_balance'] < 0 ? 'danger' : 'gray'"
            :help="$from ? 'As of ' . \Carbon\Carbon::parse($from)->format('d/m/Y') : 'Start of ledger'"
        />
        <x-reports.stat-card
            label="Total Debit (in)"
            :value="number_format($totalDebit, 2)"
            color="success"
            :help="$rows->count() . ' entries'"
        />
        <x-reports.stat-card
            label="Total Credit (out)"
            :value="number_format($totalCredit, 2)"
            color="danger"
        />
        <x-reports.stat-card
            label="Closing Balance"
            :value="number_format($ledger['closing_balance'], 2)"
            :color="$ledger['closing_balance'] < 0 ? 'danger' : 'primary'"
        />
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ $account->code }} {{ $account->name }}</x-slot>
        <x-slot name="description">GnuCash-style register — every transaction from one screen</x-slot>
        <x-slot name="afterHeader">
            <x-filament::badge color="gray">{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('entry', $rows->count()) }}</x-filament::badge>
        </x-slot>

        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr class="text-left">
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Num</th>
                        <th class="px-3 py-2 font-medium">Description</th>
                        <th class="px-3 py-2 font-medium">Transfer</th>
                        <th class="px-3 py-2 text-center font-medium">R</th>
                        <th class="px-3 py-2 text-right font-medium">Debit</th>
                        <th class="px-3 py-2 text-right font-medium">Credit</th>
                        <th class="px-3 py-2 text-right font-medium">Balance</th>
                        <th class="px-3 py-2 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @if($from)
                        <tr class="text-gray-500 dark:text-gray-400">
                            <td class="px-3 py-2" colspan="7">Opening balance</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($ledger['opening_balance'], 2) }}</td>
                            <td></td>
                        </tr>
                    @endif
                    @forelse($ledger['rows'] as $row)
                        {{-- Striped, because a register is read across nine columns
                             and the eye needs the line to follow. The just-added row
                             is tinted instead: a back-dated entry sorts into the
                             middle of the ledger rather than appearing where it was
                             typed, and without this a correct save looks like
                             nothing happened. --}}
                        {{-- Whole class names only, and only ones that survive the
                             build. dark:even:bg-white/[0.02] was the obvious choice
                             for a subtle stripe and Tailwind emits nothing for it,
                             so the stripe would simply not exist in dark mode. --}}
                        <tr @class([
                                'transition hover:bg-gray-100 dark:hover:bg-white/10',
                                'even:bg-gray-50 dark:even:bg-white/5' => $row['entry_id'] !== $this->justAdded,
                                'bg-primary-50 dark:bg-primary-500/10' => $row['entry_id'] === $this->justAdded,
                            ])>
                            <td class="whitespace-nowrap px-3 py-2">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                            <td class="px-3 py-2">
                                @if($row['num'])
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-white/10">{{ $row['num'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $row['description'] }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row['transfer'] }}</td>
                            <td class="px-3 py-2 text-center">
                                @if($row['reconciled'] === 'y')
                                    <x-filament::badge color="success" size="sm">y</x-filament::badge>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">n</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $row['debit'] ? 'text-success-600 dark:text-success-400' : '' }}">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $row['credit'] ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-medium {{ $row['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ number_format($row['balance'], 2) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right align-middle">
                                <div class="inline-flex items-center justify-end gap-0.5">
                                    @if($row['immutable_reason'])
                                        {{-- Booked by another document, reconciled, or a split: reversal is the only
                                             correction that keeps both sides consistent. --}}
                                        <span
                                            class="inline-flex h-8 w-8 items-center justify-center text-gray-300 dark:text-gray-600"
                                            title="{{ $row['immutable_reason'] }}"
                                        >
                                            <x-filament::icon icon="heroicon-m-lock-closed" class="h-4 w-4" />
                                        </span>
                                    @else
                                        @if($canEditRow)
                                            {{ ($this->editRowAction)(['entry' => $row['entry_id']]) }}
                                        @endif
                                        @if($canDeleteRow)
                                            {{ ($this->deleteRowAction)(['entry' => $row['entry_id']]) }}
                                        @endif
                                    @endif

                                    @if($canReverseRow)
                                        {{ ($this->reverseRowAction)(['entry' => $row['entry_id']]) }}
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-6 text-center text-gray-400" colspan="9">No posted transactions in this range.</td></tr>
                    @endforelse

                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                        <td class="px-3 py-2" colspan="5">Closing balance</td>
                        <td class="px-3 py-2 text-right tabular-nums text-success-600 dark:text-success-400">{{ number_format($totalDebit, 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($totalCredit, 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $ledger['closing_balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ number_format($ledger['closing_balance'], 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- The entry strip.

             Directly under the table and joined to it — same ring, no gap, its
             own tint — so it reads as the next line of the register rather than
             a form that happens to be nearby. Its fields sit under the columns
             they belong to, and the table header above is doing the labelling,
             which is why they carry none of their own.

             Under the table rather than inside it, because the Transfer column
             needs a real search box: 43 accounts in the stock chart, more in a
             real one, and a native <select> only jumps on the first characters
             of a label that begins with the account type. --}}
        @if($this->canAddInline())
            <div
                x-data
                x-on:register-row-saved.window="$nextTick(() => $el.querySelector('input, button')?.focus())"
                wire:keydown.enter.prevent="saveNewRow"
                class="-mt-px rounded-b-lg bg-warning-50 p-3 ring-1 ring-gray-950/5 dark:bg-warning-500/10 dark:ring-white/10"
            >
                {{ $this->newRowForm }}

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Enter books it and clears the line for the next one. An amount
                    goes in Debit (money in) or Credit (money out), never both.
                    Posts immediately — no draft, no approval.
                </p>
            </div>
        @endif

        {{-- Said out loud rather than silently left off: a payment scheduled ahead
             is dated at its value date, so it sits beyond the To date and shows in
             neither this register nor the Profit & Loss until it arrives.

             Loud, and with the button, because grey small print under the table was
             not enough — a payment dated a few days out reads as a payment that was
             never recorded, and the person looking for it goes hunting through the
             ledger rather than noticing a footnote. --}}
        @if ($beyond = ($ledger['beyond'] ?? null))
            <div class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-500/30 dark:bg-warning-500/10">
                <x-filament::icon
                    icon="heroicon-o-clock"
                    class="h-5 w-5 flex-shrink-0 text-warning-600 dark:text-warning-400"
                />

                <span class="text-gray-700 dark:text-gray-200">
                    Not shown:
                    <span class="font-medium">{{ $beyond['count'] }} {{ \Illuminate\Support\Str::plural('entry', $beyond['count']) }}</span>
                    dated after {{ \Illuminate\Support\Carbon::parse($this->data['to'])->toFormattedDateString() }},
                    worth <span class="font-medium tabular-nums">{{ number_format($beyond['total'], 2) }}</span>.
                    Payments are dated at their value date, so anything scheduled ahead — or entered with a
                    later date — sits past the To date.
                </span>

                <x-filament::button
                    wire:click="includeLaterEntries"
                    size="xs"
                    color="warning"
                    icon="heroicon-m-eye"
                >
                    Show them
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
