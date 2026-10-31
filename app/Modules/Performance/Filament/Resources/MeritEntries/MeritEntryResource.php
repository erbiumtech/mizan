<?php

namespace App\Modules\Performance\Filament\Resources\MeritEntries;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Performance\Filament\Resources\MeritEntries\Pages\ListMeritEntries;
use App\Modules\Performance\Models\MeritEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The ledger, read — docs/saas-launch-plan.md §8.1. Your own entries, and your
 * downline's; nothing here is edited, because nothing on the ledger ever is.
 */
class MeritEntryResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = MeritEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $modelLabel = 'Merit entry';

    protected static ?string $pluralModelLabel = 'Merit entries';

    protected static ?int $navigationSort = 62;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.user', 'recordedBy']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_on')->date('d M Y')->sortable(),
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('points')
                    ->alignEnd()
                    ->color(fn (MeritEntry $record): string => $record->points > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state),
                TextColumn::make('reason')->limit(60)->searchable(),
                TextColumn::make('source_type')->label(__('Source'))->badge()->placeholder('by hand'),
                TextColumn::make('recordedBy.name')->label(__('Recorded by'))->placeholder('system'),
                TextColumn::make('reverses_entry_id')->label(__('Reverses'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->filters([
                Filter::make('credits')->label(__('Credits only'))->query(fn (Builder $q): Builder => $q->where('points', '>', 0)),
                Filter::make('debits')->label(__('Debits only'))->query(fn (Builder $q): Builder => $q->where('points', '<', 0)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMeritEntries::route('/')];
    }
}
