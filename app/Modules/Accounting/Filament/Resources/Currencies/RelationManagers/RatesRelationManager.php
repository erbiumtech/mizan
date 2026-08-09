<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\RelationManagers;

use App\Modules\Accounting\Models\Currency;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What this currency was worth, day by day.
 *
 * One rate per day, and a lookup takes the most recent on or before the date being
 * posted — so recording today's rate does not restate last month's invoices.
 */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    protected static ?string $title = 'Rates';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('effective_on')
                ->label('From')
                ->native(false)
                ->default(now())
                ->required()
                ->helperText('Applies to anything posted on or after this date, until a later rate.'),

            TextInput::make('rate')
                ->numeric()
                ->minValue(0.00000001)
                ->required()
                ->helperText(fn (): string => Currency::baseCode().' per 1 '
                    .($this->getOwnerRecord()->code ?? 'unit').' — 304 means 1 = 304.'),

            TextInput::make('source')
                ->maxLength(255)
                ->helperText('Where it came from: a bank advice, an agreement with the client.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('effective_on')
            ->columns([
                TextColumn::make('effective_on')->label('From')->date('d M Y')->sortable(),

                TextColumn::make('rate')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 4))
                    ->description(fn (): string => Currency::baseCode().' per 1 '.($this->getOwnerRecord()->code ?? ''))
                    ->alignEnd(),

                TextColumn::make('source')->placeholder('—'),
            ])
            ->defaultSort('effective_on', 'desc')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
