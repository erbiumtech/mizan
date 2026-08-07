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
                    ->color(fn (string $state): string => match ($state) {
                        'sale' => 'info',
                        'purchase' => 'warning',
                        default => 'gray',
                    })
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

                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->money(fn (Invoice $record): string => $record->currencyCode())
                    ->state(fn (Invoice $record): float => $record->outstanding()),

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
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoicePay') ?? false) && $record->isOpen())
                ->schema(self::paymentFields())
                ->action(fn (array $data, Invoice $record) => self::run(fn (InvoiceService $s) => $s->recordPayment($record, (float) $data['amount'], $data['date'], isset($data['rate']) && $data['rate'] !== '' ? (float) $data['rate'] : null), 'Payment recorded')),

            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Invoice $record): bool => (auth()->user()?->can('InvoiceVoid') ?? false) && $record->isOpen())
                ->action(fn (Invoice $record) => self::run(fn (InvoiceService $s) => $s->void($record, auth()->user()), 'Voided')),
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
