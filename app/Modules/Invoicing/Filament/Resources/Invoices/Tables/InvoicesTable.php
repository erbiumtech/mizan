<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.tables.saved-views-bar'))
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Number')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Invoice::KIND_CREDIT_NOTE
                        ? 'Credit note'
                        : ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'sale' => 'info',
                        'purchase' => 'warning',
                        // Danger, like a void: both mean money coming back off a sale, and a
                        // credit note in a list of invoices needs to be impossible to skim past.
                        Invoice::KIND_CREDIT_NOTE => 'danger',
                        default => 'gray',
                    })
                    // What it credits, so a credit note is never an orphan on screen.
                    ->description(fn (Invoice $record): ?string => $record->isCreditNote()
                        ? 'against '.($record->creditedInvoice?->invoice_number ?? 'the balance')
                        : null)
                    ->sortable(),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->sortable(),

                TextColumn::make('project.name')
                    ->label('Project')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()
                    ->visible(fn (): bool => modules()->enabled('projects')),

                TextColumn::make('invoice_date')
                    ->label('Invoice Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'issued' => 'info',
                        'partially_paid' => 'info',
                        'paid' => 'success',
                        'void' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                // In the invoice's own currency, not the company's: that is what the
                // client is billed. Labelling a euro total "PKR" would be a lie a reader
                // has no way to catch.
                TextColumn::make('subtotal')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tax_amount')
                    ->label('Tax')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->sortable(),

                // Signed, so a credit note reads as the negative it is. An unsigned column
                // would show a credit note and the invoice it reverses as two identical
                // positive amounts, which is the one place on this screen a reader could
                // conclude the customer owes twice what they do.
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->color(fn (Invoice $record): ?string => $record->isCreditNote() ? 'danger' : null)
                    ->state(fn (Invoice $record): float => $record->signedOutstanding()),

                TextColumn::make('exchange_rate')
                    ->label('Rate')
                    ->state(fn (Invoice $record): ?string => $record->isForeignCurrency()
                        ? number_format($record->rate(), 4)
                        : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('memo')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Journal Entry')
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldsSchema::tableColumns(Invoice::class),
            ])
            ->groups([
                Group::make('status')->label('Status'),
                Group::make('kind')->label('Kind'),
            ])
            ->filters([
                // The half of "jobs" that does the work: pick a project and the
                // list becomes everything billed against that engagement. Hidden
                // with the module, so a company without Projects sees no filter
                // for a field it can never fill.
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => modules()->enabled('projects')),
            ])
            ->recordActions([
                EditAction::make(),
                ...self::invoiceActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::invoiceBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record actions — parity with Nova IssueInvoice / RecordInvoicePayment / VoidInvoice.
     *
     * @return array<Action>
     */
    protected static function invoiceActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceIssue') ?? false) && $record->isDraft())
                ->action(fn (Invoice $record) => self::run(fn (InvoiceService $s) => $s->issue($record), 'Issued')),

            Action::make('recordPayment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                // Hidden on a credit note rather than offered and refused: nobody pays one,
                // and the service says so at length if asked.
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoicePay') ?? false)
                    && $record->isOpen()
                    && ! $record->isCreditNote())
                ->schema(self::paymentFields())
                ->action(fn (array $data, Invoice $record) => self::run(fn (InvoiceService $s) => $s->recordPayment($record, (float) $data['amount'], $data['date'], isset($data['rate']) && $data['rate'] !== '' ? (float) $data['rate'] : null), 'Payment recorded')),

            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceVoid') ?? false) && $record->isOpen())
                ->action(fn (Invoice $record) => self::run(fn (InvoiceService $s) => $s->void($record, auth()->user()), 'Voided')),

            /**
             * Credit — the correction for an invoice that can no longer be voided.
             *
             * Gated on `InvoiceVoid` rather than a permission of its own, which is a decision
             * worth stating. Crediting and voiding are the same authority: both reverse a
             * posted sale, and past the 72-hour FBR window crediting *is* the void — the only
             * form of it left. A separate `InvoiceCredit` would model that as two authorities
             * and then have to be granted to every existing role before the button appeared,
             * so the people who could already reverse a sale would lose the ability to until
             * somebody noticed. If the two ever need separating, it is one permission and one
             * seeder line.
             *
             * Offered on paid invoices too, unlike Void. That is most of the point: an invoice
             * that has been reported and paid is exactly the one nothing else can correct.
             */
            Action::make('credit')
                ->label('Credit')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceVoid') ?? false)
                    && $record->isSale()
                    && ! $record->isDraft()
                    && $record->status !== Invoice::STATUS_VOID
                    && ! $record->isFullyCredited())
                ->modalHeading('Raise a credit note')
                ->modalDescription('This creates a credit note for the whole invoice as a draft. Nothing '
                    .'posts until you issue it, so you can edit its lines down first if only part of the '
                    .'invoice is being credited.')
                ->modalSubmitActionLabel('Create draft credit note')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->required()
                        ->rows(2)
                        ->maxLength(255)
                        ->helperText('The first thing anybody asks about a reversed sale, and the only part '
                            .'of it the figures cannot show. Goods returned, billed twice, priced wrong.'),

                    /**
                     * The rule 22 extension, asked for only when it is actually needed.
                     *
                     * Shown when the 180 days from the supply have passed and this company
                     * reports invoices. Asking every time would train people to ignore it, and
                     * asking a company that is not sales-tax registered would be citing a rule
                     * that does not reach them.
                     */
                    TextInput::make('commissioner_ref')
                        ->label('Commissioner extension reference')
                        ->maxLength(255)
                        ->required()
                        ->visible(fn (?Invoice $record): bool => $record !== null
                            && (bool) setting('fbr.enabled', false)
                            && ! $record->creditNoteWindowOpen())
                        ->helperText(fn (?Invoice $record): string => 'This invoice was supplied on '
                            .$record?->invoice_date->format('d M Y')
                            .', so the '.(int) setting('fbr.credit_note_days', 180)
                            .'-day period in which a credit note adjusts output tax has passed. The '
                            .'Commissioner can extend it once, in writing, on request — enter that '
                            .'reference. Without it the credit note would not be admissible.'),

                    DatePicker::make('commissioner_granted_on')
                        ->label('Granted on')
                        ->native(false)
                        ->visible(fn (?Invoice $record): bool => $record !== null
                            && (bool) setting('fbr.enabled', false)
                            && ! $record->creditNoteWindowOpen()),
                ])
                ->action(fn (array $data, Invoice $record) => self::run(
                    fn (InvoiceService $s) => $s->creditNote($record, $data['reason'], null, [
                        'ref' => $data['commissioner_ref'] ?? null,
                        'granted_on' => $data['commissioner_granted_on'] ?? null,
                    ]),
                    'Credit note drafted'
                )),
        ];
    }

    /**
     * Bulk equivalents — run over each selected invoice.
     *
     * @return array<BulkAction>
     */
    protected static function invoiceBulkActions(): array
    {
        return [
            BulkAction::make('issueBulk')
                ->label('Issue')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('InvoiceIssue') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (InvoiceService $s, Invoice $i) => $s->issue($i), 'Issued')),

            BulkAction::make('recordPaymentBulk')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => auth()->user()?->can('InvoicePay') ?? false)
                ->schema(self::paymentFields())
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (InvoiceService $s, Invoice $i) => $s->recordPayment($i, (float) $data['amount'], $data['date'], isset($data['rate']) && $data['rate'] !== '' ? (float) $data['rate'] : null), 'Payment recorded')),

            BulkAction::make('voidBulk')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('InvoiceVoid') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (InvoiceService $s, Invoice $i) => $s->void($i, auth()->user()), 'Voided')),
        ];
    }

    /**
     * @return array<Field>
     */
    protected static function paymentFields(): array
    {
        return [
            DatePicker::make('date')->required()->default(now()->toDateString()),
            TextInput::make('amount')->numeric()->step(0.01)->required()->minValue(0.01)
                ->helperText(fn (?Invoice $record): ?string => $record?->isForeignCurrency()
                    ? 'In '.$record->currencyCode().', which is what the invoice is billed in.'
                    : null),

            // A bank advice saying what actually landed is a fact, and the rate table is
            // only an estimate of it — so the fact can be typed in.
            TextInput::make('rate')
                ->label('Rate the bank gave')
                ->numeric()
                ->minValue(0)
                ->visible(fn (?Invoice $record): bool => $record?->isForeignCurrency() ?? false)
                ->helperText('Leave blank to use the rate in force on the payment date. The difference from '
                    .'the rate the invoice was raised at is a realised gain or loss.'),
        ];
    }

    /**
     * Run a single-invoice operation, surfacing service validation errors as notifications.
     */
    protected static function run(callable $op, string $label): void
    {
        try {
            $op(app(InvoiceService::class));
            Notification::make()->title("{$label}: processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $label): void
    {
        $service = app(InvoiceService::class);
        $done = 0;
        try {
            foreach ($records as $record) {
                $op($service, $record);
                $done++;
            }
            Notification::make()->title("{$label}: {$done} invoice(s) processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
