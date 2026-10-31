<?php

namespace App\Modules\Advances\Filament\Resources\AdvanceTypes;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Modules\Advances\Filament\Resources\AdvanceTypes\Pages\CreateAdvanceType;
use App\Modules\Advances\Filament\Resources\AdvanceTypes\Pages\EditAdvanceType;
use App\Modules\Advances\Filament\Resources\AdvanceTypes\Pages\ListAdvanceTypes;
use App\Modules\Advances\Models\AdvanceType;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Advance types — docs/saas-launch-plan.md §14.3. */
class AdvanceTypeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = AdvanceType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Advance Types';

    protected static ?int $navigationSort = 34;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true)->placeholder('Salary advance, Eid advance, Loan'),
            TextInput::make('max_amount')->label(__('Maximum amount'))->numeric()->minValue(1)->helperText('Blank for no ceiling.'),
            TextInput::make('max_instalments')->label(__('Maximum instalments'))->numeric()->minValue(1)->maxValue(120),
            TextInput::make('interest_rate_pct')->label(__('Interest (% of the amount)'))->numeric()->minValue(0)->maxValue(100)->default(0)->helperText('Simple, added once to what is recovered. Zero for an interest-free advance.'),
            TextInput::make('active_limit')->label(__('Active at once, per person'))->numeric()->minValue(1)->helperText('Blank for no limit.'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('max_amount')->numeric(0)->placeholder('—'),
                TextColumn::make('max_instalments')->placeholder('—'),
                TextColumn::make('interest_rate_pct')->label(__('Interest %'))->numeric(2),
                TextColumn::make('active_limit')->label(__('Active at once'))->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label(__('Active')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdvanceTypes::route('/'),
            'create' => CreateAdvanceType::route('/create'),
            'edit' => EditAdvanceType::route('/{record}/edit'),
        ];
    }
}
