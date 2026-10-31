<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Schemas;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

/**
 * An invoice, read — the Material "Record detail (Invoice)" design.
 *
 * Four tabs. **Overview** is the document: who, when, the lines, and beside them a summary of what it comes to
 * and what is left, with its latest few events. **Payments** is what has been received or raised against it,
 * **Ledger entries** the journal lines it posted (shown to whoever may read the journal), and **Activity** its
 * whole history — the same `InvoiceEvent` log the edit page's History tab lists.
 *
 * Everything reads from relations the page loads with the record (`ViewInvoice::resolveRecord()`), so a
 * list of lines or events never loads its parent one row at a time. Money is in the invoice's own currency.
 */
class InvoiceInfolist
{
    /** Events that are money moving, or about to: what the Payments tab lists. */
    public const PAYMENT_EVENTS = [InvoiceEvent::PAYMENT, InvoiceEvent::TAX_WITHHELD, InvoiceEvent::PAYMENT_RAISED];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('invoice')
                ->contained(false)
                ->extraAttributes(['class' => 'md-detail-tabs'])
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make(__('Overview'))->schema([self::overview()]),
                    Tab::make(__('Payments'))->schema(self::payments()),
                    Tab::make(__('Ledger entries'))
                        ->visible(fn (): bool => auth()->user()?->can('viewAny', JournalEntry::class) ?? false)
                        ->schema(self::ledger()),
                    Tab::make(__('Activity'))->schema([self::history()]),
                ]),
        ]);
    }

    private static function overview(): Flex
    {
        return Flex::make([
            Section::make()
                ->extraAttributes(['class' => 'md-detail-main'])
                ->schema([
                    Grid::make(['default' => 2, 'lg' => 4])
                        ->extraAttributes(['class' => 'md-detail-meta'])
                        ->schema([
                            TextEntry::make('contact.name')
                                ->label(fn (Invoice $record): string => $record->kind === Invoice::KIND_PURCHASE ? __('Supplier') : __('Customer'))
                                ->placeholder('—'),
                            TextEntry::make('invoice_date')->label(__('Issued'))->date('d M Y'),
                            TextEntry::make('due_date')
                                ->label(__('Due'))
                                ->date('d M Y')
                                ->placeholder('—')
                                ->color(fn (Invoice $record): ?string => self::isOverdue($record) ? 'danger' : null),
                            TextEntry::make('project.name')
                                ->label(__('Project'))
                                ->placeholder('—')
                                ->visible(fn (): bool => modules()->enabled('projects')),
                        ]),

                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->extraAttributes(['class' => 'md-detail-lines'])
                        ->table([
                            TableColumn::make(__('Item')),
                            TableColumn::make(__('Qty'))->alignEnd(),
                            TableColumn::make(__('Rate'))->alignEnd(),
                            TableColumn::make(__('Amount'))->alignEnd(),
                        ])
                        ->schema([
                            TextEntry::make('description'),
                            TextEntry::make('quantity')->numeric(decimalPlaces: 2)->alignEnd(),
                            TextEntry::make('unit_price')->money(fn ($livewire): string => $livewire->getRecord()->currencyCode())->alignEnd(),
                            TextEntry::make('line_total')->money(fn ($livewire): string => $livewire->getRecord()->currencyCode())->alignEnd(),
                        ]),
                ]),

            Group::make([
                Section::make(__('Summary'))
                    ->extraAttributes(['class' => 'md-detail-summary'])
                    ->schema([
                        self::amount('subtotal', __('Subtotal')),
                        self::amount('tax_amount', __('Sales tax')),
                        self::amount('amount_paid', __('Paid')),
                        TextEntry::make('balance_due')
                            ->label(__('Balance due'))
                            ->inlineLabel()
                            ->state(fn (Invoice $record): float => $record->outstanding())
                            ->money(fn (Invoice $record): string => $record->currencyCode())
                            ->weight(FontWeight::Bold)
                            ->alignEnd()
                            ->extraEntryWrapperAttributes(['class' => 'md-detail-summary-total']),
                    ]),

                Section::make(__('Activity'))
                    ->extraAttributes(['class' => 'md-detail-activity'])
                    ->schema([
                        RepeatableEntry::make('recent_events')
                            ->hiddenLabel()
                            ->state(fn (Invoice $record) => $record->events->take(5)->values())
                            ->extraAttributes(['class' => 'md-timeline'])
                            ->placeholder(__('Nothing has happened to this invoice yet.'))
                            ->schema([
                                TextEntry::make('description')->hiddenLabel(),
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->since()
                                    ->size(TextSize::ExtraSmall)
                                    ->color('gray'),
                            ]),
                    ]),
            ])
                ->grow(false)
                ->extraAttributes(['class' => 'md-detail-aside']),
        ])->from('lg');
    }

    private static function amount(string $column, string $label): TextEntry
    {
        return TextEntry::make($column)
            ->label($label)
            ->inlineLabel()
            ->money(fn (Invoice $record): string => $record->currencyCode())
            ->alignEnd();
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function payments(): array
    {
        return [
            RepeatableEntry::make('payment_events')
                ->hiddenLabel()
                ->state(fn (Invoice $record) => $record->events
                    ->whereIn('event', self::PAYMENT_EVENTS)
                    ->values())
                ->placeholder(__('No payments recorded yet. Record one from the button at the top of the page.'))
                ->table([
                    TableColumn::make(__('When')),
                    TableColumn::make(__('What')),
                    TableColumn::make(__('Amount'))->alignEnd(),
                    TableColumn::make(__('By')),
                ])
                ->schema([
                    TextEntry::make('created_at')->dateTime('d M Y H:i'),
                    TextEntry::make('description'),
                    TextEntry::make('amount')
                        ->money(fn ($livewire): string => $livewire->getRecord()->currencyCode())
                        ->placeholder('—')
                        ->alignEnd(),
                    TextEntry::make('causer.name')->placeholder(__('the system')),
                ]),
        ];
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function ledger(): array
    {
        return [
            RepeatableEntry::make('ledger_lines')
                ->hiddenLabel()
                ->state(fn (Invoice $record): array => self::ledgerLines($record))
                ->placeholder(__('Nothing posted yet: a draft reaches the ledger when it is issued.'))
                ->table([
                    TableColumn::make(__('Date')),
                    TableColumn::make(__('Entry')),
                    TableColumn::make(__('Account')),
                    TableColumn::make(__('Debit'))->alignEnd(),
                    TableColumn::make(__('Credit'))->alignEnd(),
                ])
                ->schema([
                    TextEntry::make('date'),
                    TextEntry::make('entry'),
                    TextEntry::make('account'),
                    TextEntry::make('debit')->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd(),
                    TextEntry::make('credit')->numeric(decimalPlaces: 2)->placeholder('—')->alignEnd(),
                ]),
        ];
    }

    private static function history(): RepeatableEntry
    {
        return RepeatableEntry::make('events')
            ->hiddenLabel()
            ->placeholder(__('Nothing has happened to this invoice yet.'))
            ->table([
                TableColumn::make(__('When')),
                TableColumn::make(__('Event')),
                TableColumn::make(__('Description')),
                TableColumn::make(__('Amount'))->alignEnd(),
                TableColumn::make(__('By')),
            ])
            ->schema([
                TextEntry::make('created_at')->dateTime('d M Y H:i'),
                TextEntry::make('event')->badge()->color(fn (InvoiceEvent $record): string => $record->color()),
                TextEntry::make('description'),
                TextEntry::make('amount')
                    ->money(fn ($livewire): string => $livewire->getRecord()->currencyCode())
                    ->placeholder('—')
                    ->alignEnd(),
                TextEntry::make('causer.name')->placeholder(__('the system')),
            ]);
    }

    /**
     * The journal lines this invoice posted, oldest entry first.
     *
     * Entries carry the invoice as their source (InvoiceService::postSystemEntry()); the invoice's own
     * `journal_entry_id` is added too, for entries posted before the source was recorded. Amounts are in the
     * company's currency, which is what the ledger is kept in.
     *
     * @return array<int, array{date: string, entry: string, account: string, debit: ?float, credit: ?float}>
     */
    public static function ledgerLines(Invoice $record): array
    {
        $entries = JournalEntry::query()
            ->where(fn ($query) => $query
                ->forSource(Invoice::class, $record->getKey())
                ->when($record->journal_entry_id, fn ($q, $id) => $q->orWhere('id', $id)))
            ->with('lines.account')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $rows[] = [
                    'date' => $entry->entry_date?->format('d M Y') ?? '—',
                    'entry' => (string) $entry->entry_number,
                    'account' => trim(($line->account?->code ?? '').' '.($line->account?->name ?? '')),
                    'debit' => (float) $line->debit_amount > 0 ? (float) $line->debit_amount : null,
                    'credit' => (float) $line->credit_amount > 0 ? (float) $line->credit_amount : null,
                ];
            }
        }

        return $rows;
    }

    public static function isOverdue(Invoice $record): bool
    {
        return $record->isOpen()
            && $record->due_date !== null
            && $record->due_date->lt(now()->startOfDay())
            && $record->outstanding() > 0.004;
    }

    /**
     * What the chip beside the invoice number says, and in which tone.
     *
     * @return array{label: string, tone: 'error'|'success'|'neutral'|'tonal'}
     */
    public static function statusChip(Invoice $record): array
    {
        if (self::isOverdue($record)) {
            $days = (int) $record->due_date->startOfDay()->diffInDays(now()->startOfDay());

            return [
                'label' => trans_choice('Overdue :count day|Overdue :count days', $days, ['count' => $days]),
                'tone' => 'error',
            ];
        }

        return match ($record->status) {
            Invoice::STATUS_PAID => ['label' => __('Paid'), 'tone' => 'success'],
            Invoice::STATUS_DRAFT => ['label' => __('Draft'), 'tone' => 'neutral'],
            Invoice::STATUS_VOID => ['label' => __('Void'), 'tone' => 'neutral'],
            Invoice::STATUS_PARTIALLY_PAID => ['label' => __('Partly paid'), 'tone' => 'tonal'],
            default => ['label' => __('Open'), 'tone' => 'tonal'],
        };
    }
}
