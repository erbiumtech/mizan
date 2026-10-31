<?php

namespace App\Modules\Leave\Filament\Resources\LeaveGroups;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Leave\Filament\Resources\LeaveGroups\Pages\CreateLeaveGroup;
use App\Modules\Leave\Filament\Resources\LeaveGroups\Pages\EditLeaveGroup;
use App\Modules\Leave\Filament\Resources\LeaveGroups\Pages\ListLeaveGroups;
use App\Modules\Leave\Models\LeaveGroup;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Leave groups — docs/saas-launch-plan.md §14.4. */
class LeaveGroupResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LeaveGroup::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Sheet;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Leave Groups';

    protected static ?int $navigationSort = 33;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true)->placeholder('Office staff, Factory floor, Contract'),
            Toggle::make('is_default')->label(__('Default group'))->helperText('Applies to every employee with no group of their own. One group is the default.'),
            Select::make('types')
                ->label(__('Leave types in this group'))
                ->relationship('types', 'label', fn ($query) => $query->where('is_active', true)->orderBy('sort'))
                ->multiple()
                ->preload()
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                IconColumn::make('is_default')->boolean()->label(__('Default')),
                TextColumn::make('types_count')->counts('types')->label(__('Types')),
                TextColumn::make('employees_count')->counts('employees')->label(__('People')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveGroups::route('/'),
            'create' => CreateLeaveGroup::route('/create'),
            'edit' => EditLeaveGroup::route('/{record}/edit'),
        ];
    }
}
