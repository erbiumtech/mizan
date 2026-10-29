<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeBooks;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages\CreateChequeBook;
use App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages\EditChequeBook;
use App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages\ListChequeBooks;
use App\Modules\Accounting\Models\ChequeBook;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** A numbered range per account — docs/saas-launch-plan.md §8.6. */
class ChequeBookResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ChequeBook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Cheque Books';

    protected static ?int $navigationSort = 26;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_bank_account_id')
                ->label('Bank account')
                ->relationship('companyBankAccount', 'title')
                ->required()
                ->preload()
                ->searchable(),

            TextInput::make('prefix')
                ->maxLength(10)
                ->helperText('Printed before the number, if the leaves carry one.'),

            TextInput::make('first_no')
                ->label('First number')
                ->numeric()
                ->minValue(1)
                ->required(),

            TextInput::make('last_no')
                ->label('Last number')
                ->numeric()
                ->required()
                ->rule(fn (Get $get) => 'gte:'.(int) $get('first_no')),

            TextInput::make('next_no')
                ->label('Next to print')
                ->numeric()
                ->helperText('Leave blank to start at the first number. Set it when leaves were already used by hand.')
                ->visibleOn('edit'),

            Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('companyBankAccount.title')->label('Bank account')->sortable(),
                TextColumn::make('range')->label('Range')->state(fn (ChequeBook $record): string => $record->label()),
                TextColumn::make('next_no')->label('Next')->state(fn (ChequeBook $record): string => $record->isExhausted() ? 'Used up' : $record->format($record->next_no)),
                TextColumn::make('remaining')->label('Left')->state(fn (ChequeBook $record): int => $record->remaining()),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChequeBooks::route('/'),
            'create' => CreateChequeBook::route('/create'),
            'edit' => EditChequeBook::route('/{record}/edit'),
        ];
    }
}
