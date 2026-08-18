<?php

namespace App\Modules\Core\Filament\Resources\ActivityLogs;

use App\Modules\Core\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Modules\Core\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Modules\Core\Filament\Resources\ActivityLogs\Schemas\ActivityLogInfolist;
use App\Modules\Core\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Modules\Core\Models\ActivityLog as Activity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Audit & Taxes';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'Activity Log';

    protected static ?string $pluralModelLabel = 'Activity Logs';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'description', 'log_name'];
    }

    /**
     * The causer, eager-loaded, because both screens name it.
     *
     * Filament eager-loads a column's relationship only when the column is named
     * for it — `causer.name` would be loaded automatically, `causer` is not
     * (HasCellState::hasRelationship returns false without a dot). Both the table
     * and the infolist read `$record->causer?->name` out of a state closure
     * instead, which is one query per row and, with lazy loading disabled outside
     * production, a LazyLoadingViolationException on the first row rather than a
     * slow page.
     *
     * The name cannot simply become `causer.name`: this is a morphTo, so there is
     * no column to sort or search by, and the closure is also what turns a null
     * causer into "System".
     *
     * On getEloquentQuery() rather than on the table, because the view page
     * resolves its record through here too and its infolist reads the same
     * relation.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('causer');
    }

    public static function infolist(Schema $schema): Schema
    {
        return ActivityLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
