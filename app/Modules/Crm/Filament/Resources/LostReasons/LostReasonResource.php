<?php

namespace App\Modules\Crm\Filament\Resources\LostReasons;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Crm\Filament\Resources\LostReasons\Pages\CreateLostReason;
use App\Modules\Crm\Filament\Resources\LostReasons\Pages\EditLostReason;
use App\Modules\Crm\Filament\Resources\LostReasons\Pages\ListLostReasons;
use App\Modules\Crm\Models\LostReason;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Why deals are lost — the list a deal's Lost action picks from.
 *
 * A row rather than free text for the reason the lead sources are: win/loss by reason is the report
 * worth having, and "price", "Price" and "too expensive" are three reasons with three rates. Until this
 * screen there was no way to add one, so a fresh company's Lost action offered an empty list.
 */
class LostReasonResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LostReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedXCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Lost reason';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('One row per reason. Two rows meaning the same thing split its share of the losses in half.'),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Switch off rather than delete: deals already lost for this reason keep it.'),

            TextInput::make('sort')->label('Order in lists')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('opportunities_count')->label('Deals lost')->counts('opportunities')->alignEnd()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLostReasons::route('/'),
            'create' => CreateLostReason::route('/create'),
            'edit' => EditLostReason::route('/{record}/edit'),
        ];
    }
}
