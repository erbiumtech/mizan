<?php

namespace App\Modules\Lifecycle\Filament\Resources\Separations;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\Separations\Pages\CreateSeparation;
use App\Modules\Lifecycle\Filament\Resources\Separations\Pages\EditSeparation;
use App\Modules\Lifecycle\Filament\Resources\Separations\Pages\ListSeparations;
use App\Modules\Lifecycle\Filament\Resources\Separations\RelationManagers\ClearanceItemsRelationManager;
use App\Modules\Lifecycle\Models\Separation;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Separations with a clearance list — docs/saas-launch-plan.md §14.5. */
class SeparationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Separation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Separations';

    protected static ?int $navigationSort = 53;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.user', 'items']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label(__('Employee'))
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->required()->disabledOn('edit'),
            Select::make('type')->options(Separation::TYPES)->required(),
            DatePicker::make('notice_on')->label(__('Notice given on'))->native(false)->nullable(),
            DatePicker::make('last_working_day')->native(false)->required()->helperText('The record is closed and the status set the day after. The login closes after the grace under Company Settings.'),
            Textarea::make('reason')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('type')->formatStateUsing(fn (string $state): string => Separation::TYPES[$state] ?? $state),
                TextColumn::make('notice_on')->date('d M Y')->placeholder('—'),
                TextColumn::make('last_working_day')->date('d M Y')->sortable(),
                TextColumn::make('clearance')->label(__('Clearance'))->state(fn (Separation $s): string => ($c = $s->clearance())['clear'].' of '.$c['total'])
                    ->color(fn (Separation $s): string => $s->isCleared() ? 'success' : 'warning'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'closed' ? 'gray' : 'warning'),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed'])])
            ->defaultSort('last_working_day', 'desc')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ClearanceItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeparations::route('/'),
            'create' => CreateSeparation::route('/create'),
            'edit' => EditSeparation::route('/{record}/edit'),
        ];
    }
}
