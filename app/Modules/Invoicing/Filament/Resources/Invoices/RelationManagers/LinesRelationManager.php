<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\RelationManagers;

use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->label('Product')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->nullable()
                ->helperText('Optional — link to an inventory product'),

            TextInput::make('description')
                ->required()
                ->maxLength(255),

            TextInput::make('quantity')
                ->numeric()
                ->step(0.01)
                ->required()
                ->minValue(0.01),

            TextInput::make('unit_price')
                ->label('Unit Price')
                ->numeric()
                ->step(0.01)
                ->required()
                ->minValue(0),

            TextInput::make('line_total')
                ->label('Line Total')
                ->numeric()
                ->step(0.01)
                ->required()
                ->minValue(0),

            Select::make('account_id')
                ->label('Account Override')
                ->relationship('account', 'name')
                ->searchable()
                ->preload()
                ->nullable()
                ->helperText('Optional GL account override'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->toggleable(),

                TextColumn::make('description')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->numeric(),

                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('PKR'),

                TextColumn::make('line_total')
                    ->label('Line Total')
                    ->money('PKR'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('InvoiceUpdate') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Model $record): bool => self::canModify($record)),
                DeleteAction::make()
                    ->visible(fn (Model $record): bool => self::canModify($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function canModify(Model $record): bool
    {
        $invoice = $record->invoice;

        return ($invoice instanceof Invoice && $invoice->isDraft())
            && (auth()->user()?->can('InvoiceUpdate') ?? false);
    }
}
