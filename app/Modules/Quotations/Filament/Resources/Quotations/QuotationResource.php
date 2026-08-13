<?php

namespace App\Modules\Quotations\Filament\Resources\Quotations;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Crm\Models\Lead;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Quotations\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Modules\Quotations\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Modules\Quotations\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use RuntimeException;
use UnitEnum;

/**
 * Quotes.
 *
 * **A quote touches nothing.** No journal entry, not even a pending one — it is an offer, and
 * nothing has happened. Ledger involvement begins when it becomes an invoice, and that invoice
 * is created as a **draft**: issuing it is what transmits to FBR, and since digital invoicing a
 * transmitted invoice cannot be freely voided after 72 hours.
 *
 * **A sent quote is revised, never edited.** The customer has it in their inbox.
 */
class QuotationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Quote';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who it is for')
                ->description('A customer or a lead. A quote to a lead cannot become an invoice until the lead is converted — an invoice needs a party the ledger can bill.')
                ->schema([
                    Select::make('contact_id')
                        ->label('Customer')
                        ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),

                    Select::make('lead_id')
                        ->label('Lead')
                        ->options(fn (): array => Lead::query()
                            ->open()
                            ->orderByDesc('id')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn (Lead $lead): array => [$lead->id => $lead->display_label])
                            ->all())
                        ->searchable()
                        ->visible(fn (): bool => modules()->enabled('crm')),
                ])
                ->columns(2),

            Section::make('The offer')
                ->schema([
                    DatePicker::make('issue_date')->native(false)->default(now())->required(),

                    DatePicker::make('valid_until')
                        ->native(false)
                        ->default(now()->addDays(30))
                        ->helperText('After this it cannot be accepted — the prices may no longer hold. Expiring happens once, not as a daily reminder.'),

                    TextInput::make('currency_code')->label('Currency')->maxLength(3)->placeholder('PKR'),
                    TextInput::make('exchange_rate')->numeric(),

                    Textarea::make('terms')->rows(2)->columnSpanFull(),
                ])
                ->columns(2),

            Repeater::make('lines')
                ->relationship()
                ->label('Lines')
                ->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->visible(fn (): bool => modules()->enabled('inventory')),

                    TextInput::make('description')->required()->maxLength(255)->columnSpan(2),
                    TextInput::make('quantity')->numeric()->default(1)->required(),
                    TextInput::make('unit_price')->numeric()->default(0)->required(),

                    TextInput::make('discount_pct')
                        ->label('Discount %')
                        ->numeric()
                        ->default(0)
                        ->helperText('The invoice carries the resulting price, not the negotiation.'),

                    Select::make('tax_rate_id')
                        ->label('Tax')
                        ->options(fn (): array => TaxRate::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('The same rates invoices use, so a converted line carries the same treatment rather than a recalculation.'),
                ])
                ->columns(3)
                ->orderColumn('sort')
                ->defaultItems(1)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    // Version 2 of a quote is a different document from version 1, and saying so
                    // in the list is what stops somebody reading the wrong one to a customer.
                    ->description(fn (Quotation $record): ?string => $record->version > 1
                        ? "version {$record->version}"
                        : null),

                TextColumn::make('party')
                    ->label('For')
                    ->state(fn (Quotation $record): string => $record->partyLabel())
                    ->searchable(false),

                TextColumn::make('total')->money('PKR')->alignEnd()->sortable(),

                TextColumn::make('valid_until')
                    ->label('Valid to')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->color(fn (Quotation $record): string => $record->hasExpired() ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Quotation::STATUS_ACCEPTED => 'success',
                        Quotation::STATUS_SENT => 'info',
                        Quotation::STATUS_DECLINED, Quotation::STATUS_EXPIRED => 'danger',
                        Quotation::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Quotation $record): ?string => $record->invoice_id
                        ? 'invoiced (draft)'
                        : $record->decline_reason)
                    ->sortable(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Quotation::STATUS_DRAFT => 'Draft',
                    Quotation::STATUS_SENT => 'Sent',
                    Quotation::STATUS_ACCEPTED => 'Accepted',
                    Quotation::STATUS_DECLINED => 'Declined',
                    Quotation::STATUS_EXPIRED => 'Expired',
                    Quotation::STATUS_SUPERSEDED => 'Superseded',
                ]),
            ])
            ->recordActions([
                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Its figures are fixed from here on. Changing it afterwards means a new version, so your copy and the customer\'s never disagree.')
                    ->visible(fn (Quotation $record): bool => $record->isDraft()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Quotation $record): void {
                        try {
                            app(QuotationService::class)->send($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Sent.')->send();
                    }),

                Action::make('accept')
                    ->label('Accepted')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Quotation $record): bool => $record->isSent()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Quotation $record): void {
                        try {
                            app(QuotationService::class)->accept($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Accepted.')->send();
                    }),

                Action::make('revise')
                    ->label('Revise')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Creates the next version and marks this one superseded. This version stays exactly as it was sent, so what you offered in the past is still reproducible.')
                    ->visible(fn (Quotation $record): bool => ! $record->isAccepted()
                        && $record->invoice_id === null
                        && (auth()->user()?->can('create', Quotation::class) ?? false))
                    ->action(function (Quotation $record): void {
                        try {
                            $revision = app(QuotationService::class)->revise($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Version {$revision->version} created as {$revision->number}.")
                            ->send();
                    }),

                Action::make('convert')
                    ->label('Raise the invoice')
                    ->icon('heroicon-o-receipt-percent')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Creates a DRAFT invoice copying these lines, tax rates and currency. It is not issued: issuing is what reports it to FBR, and a reported invoice cannot be freely cancelled after 72 hours.')
                    ->visible(fn (Quotation $record): bool => auth()->user()?->can('convert', $record) ?? false)
                    ->action(function (Quotation $record): void {
                        try {
                            $invoice = app(QuotationService::class)->convertToInvoice($record);
                        } catch (InvalidArgumentException|RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Draft invoice {$invoice->invoice_number} created.")
                            ->body('Check it and issue it from Invoicing when you are ready.')
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }
}
