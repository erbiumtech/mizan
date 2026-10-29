<?php

namespace App\Modules\Payroll\Filament\Resources\SalaryStructures;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages\CreateSalaryStructure;
use App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages\EditSalaryStructure;
use App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages\ListSalaryStructures;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\SalaryStructureLine;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
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

/** Salary structures — docs/saas-launch-plan.md §14.1. */
class SalaryStructureResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = SalaryStructure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Salary Structures';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true),
            TextInput::make('description')->maxLength(190)->helperText('Who it is for: "Factory floor", "Office staff".'),
            Toggle::make('is_active')->default(true),
            Repeater::make('lines')
                ->relationship()
                ->label('Components')
                ->orderColumn('sort')
                ->columns(3)
                ->defaultItems(1)
                ->schema([
                    Select::make('pay_component_id')
                        ->label('Component')
                        ->options(fn (): array => PayComponent::query()->active()->earnings()->orderBy('sort')->pluck('label', 'id')->all())
                        ->required()
                        ->distinct(),
                    Select::make('mode')->options(SalaryStructureLine::MODES)->default(SalaryStructureLine::MODE_SHARE)->required()->live(),
                    TextInput::make('value')
                        ->label(fn (Get $get): string => $get('mode') === SalaryStructureLine::MODE_FIXED ? 'Amount' : 'Percent of gross')
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => $get('mode') !== SalaryStructureLine::MODE_BALANCE)
                        ->required(fn (Get $get): bool => $get('mode') !== SalaryStructureLine::MODE_BALANCE),
                ])
                ->helperText('Shares and fixed amounts are taken first; one component may take the balance of gross. The sum need not reach gross.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('description')->limit(50),
                TextColumn::make('lines_count')->counts('lines')->label('Components'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryStructures::route('/'),
            'create' => CreateSalaryStructure::route('/create'),
            'edit' => EditSalaryStructure::route('/{record}/edit'),
        ];
    }
}
