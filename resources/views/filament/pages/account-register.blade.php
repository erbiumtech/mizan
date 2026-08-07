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

                    {{-- The blank entry row.

                         The last row of the table, not a form under it: same
                         columns, same alignment, same type. That is what makes a
                         register a register — you read down to the bottom and
                         keep typing.

                         Bare inputs rather than Filament components, because a
                         form field brings a label, a wrapper and an error block
                         that all have to be fought back out of a table cell.
                         wire:model is deferred by default, so typing costs
                         nothing until Enter — which matters here, since every
                         render of this page recomputes the whole ledger. --}}
                    @if($this->canAddInline())
                        @php($newRowInput = 'w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:italic placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:text-white dark:placeholder:text-gray-500')
                        <tr
                            x-data
                            x-on:register-row-saved.window="$nextTick(() => $refs.newRowDate?.focus())"
                            wire:key="register-new-row"
                            class="bg-warning-50 dark:bg-warning-500/10"
                        >
                            <td class="whitespace-nowrap px-3 py-2">
                                <input
                                    type="date"
                                    x-ref="newRowDate"
                                    wire:model="newRow.date"
                                    wire:keydown.enter.prevent="saveNewRow"
                                    class="{{ $newRowInput }}"
                                >
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    type="text"
                                    placeholder="Num"
                                    maxlength="50"
                                    wire:model="newRow.num"
                                    wire:keydown.enter.prevent="saveNewRow"
                                    class="{{ $newRowInput }}"
                                >
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    type="text"
                                    placeholder="Description"
                                    maxlength="255"
                                    wire:model="newRow.description"
                                    wire:keydown.enter.prevent="saveNewRow"
                                    class="{{ $newRowInput }}"
                                >
                            </td>
                            <td class="px-3 py-2">
                                {{-- A native select, grouped by account type. It is
                                     type-to-search on every platform for free, which
                                     is what this column needs. --}}
                                <select
                                    wire:model="newRow.transfer_account_id"
                                    wire:keydown.enter.prevent="saveNewRow"
                                    class="{{ $newRowInput }} text-right"
                                >
                                    <option value="">Transfer</option>
                                    @foreach($this->transferOptions()->groupBy('type') as $type => $options)
                                        <optgroup label="{{ ucfirst($type) }}">
                                            @foreach($options as $option)
                                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-center text-gray-400 dark:text-gray-500">n</td>
                            <td class="px-3 py-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="Debit"
                                    wire:model="newRow.debit"
                                    wire:keydown.enter.prevent="saveNewRow"
                                    class="{{ $newRowInput }} text-right tabular-nums"
                                >
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="Credit"
                                    wire:model="newRow.credit"
                                    wire:keydown.enter.prevent="saveNewRow"
                                    class="{{ $newRowInput }} text-right tabular-nums"
                                >
                            </td>
                            <td class="px-3 py-2 text-right text-sm italic text-gray-400 dark:text-gray-500">Balance</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                <div class="inline-flex items-center justify-end gap-0.5">
                                    <x-filament::icon-button
                                        icon="heroicon-m-check"
                                        color="success"
                                        size="sm"
                                        label="Book this transaction"
                                        wire:click="saveNewRow"
                                        wire:loading.attr="disabled"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-x-mark"
                                        color="gray"
                                        size="sm"
                                        label="Clear the row"
                                        wire:click="resetNewRow"
                                    />
                                </div>
                            </td>
                        </tr>

                        {{-- Errors under the row rather than in a toast: what was
                             typed is still on screen, so the screen can point at
                             the box that is wrong. A notification cannot, and it
                             disappears. --}}
                        @php($newRowErrors = collect($errors->keys())->filter(fn (string $key): bool => str_starts_with($key, 'newRow.')))
                        @if($newRowErrors->isNotEmpty())
                            <tr class="bg-warning-50 dark:bg-warning-500/10">
                                <td colspan="9" class="px-3 pb-2 pt-0">
                                    <ul class="space-y-0.5 text-xs text-danger-600 dark:text-danger-400">
                                        @foreach($newRowErrors as $key)
                                            <li>{{ $errors->first($key) }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endif
                    @endif
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
