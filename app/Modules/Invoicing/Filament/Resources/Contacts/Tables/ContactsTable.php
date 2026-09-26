<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Services\CustomerStatement;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('payment_terms_days')
                    ->label('Terms')
                    ->state(fn (Contact $record): string => $record->paymentTermsLabel())
                    ->color(fn (Contact $record): string => $record->payment_terms_days === null ? 'gray' : 'primary')
                    ->toggleable(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'customer' => 'info',
                        'supplier' => 'warning',
                        'both' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                ...CustomFieldsSchema::tableColumns(Contact::class),
            ])
            ->filters([
                ...CustomFieldsSchema::tableFilters(Contact::class),
            ])
            ->recordActions([
                EditAction::make(),

                /**
                 * This customer's statement of account, for any period, as a PDF — the on-demand half of
                 * docs/erpnext-gap-plan.md §4 item 1. The monthly email is the other half (Settings → Customer
                 * statements); this is for the customer who rings up and asks.
                 */
                Action::make('statement')
                    ->label('Statement')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (Contact $record): bool => (auth()->user()?->can('InvoiceView') ?? false)
                        && in_array($record->kind, ['customer', 'both'], true))
                    ->modalHeading(fn (Contact $record): string => "Statement of account — {$record->name}")
                    ->modalSubmitActionLabel('Download')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->required()
                            ->default(now()->startOfYear()->toDateString()),
                        DatePicker::make('to')
                            ->label('To')
                            ->required()
                            ->default(now()->toDateString())
                            ->afterOrEqual('from'),
                    ])
                    ->action(function (Contact $record, array $data) {
                        $statements = app(CustomerStatement::class);
                        $pdf = $statements->renderPdf($record, $data['from'], $data['to']);

                        // `raw()` for the reason the letters give: a streamed response's getContent() is
                        // false under Dompdf, and `echo false` is a 0-byte PDF that looks like a document.
                        return response()->streamDownload(
                            fn () => print ($pdf->raw()),
                            $statements->filename($record, $data['to']),
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
