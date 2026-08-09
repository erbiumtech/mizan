<?php

namespace App\Modules\Core\Filament\Platform\Resources\ActivityLogs;

use App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages\ListPlatformActivityLogs;
use App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages\ViewPlatformActivityLog;
use App\Modules\Core\Filament\Resources\ActivityLogs\Schemas\ActivityLogInfolist;
use App\Modules\Core\Models\ActivityLog as Activity;
use App\Modules\Core\Models\Company;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every company's audit trail, in one list.
 *
 * Added alongside the company panel's activity log rather than replacing it: a company's
 * administrator reading their own trail is legitimate and useful, and taking it away would
 * be a loss of function for them dressed up as a move. What is new here is the
 * cross-company view, which they should not have.
 *
 * The scoping is already right without doing anything: ActivityLog filters reads to the
 * current company *when one is current*, and there is none on this panel — so the same
 * model returns everything, and the company column and filter are what make that readable
 * rather than confusing.
 */
class PlatformActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'Activity';

    protected static ?string $slug = 'activity';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'description', 'log_name'];
    }

    public static function infolist(Schema $schema): Schema
    {
        return ActivityLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Which company this happened in — the column the company panel's version
                // has no need for, and the only one that makes a shared list readable.
                TextColumn::make('company_id')
                    ->label('Company')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => Company::find($state)?->name ?? 'no company')
                    ->sortable(),

                TextColumn::make('log_name')->label('Model')->searchable()->sortable(),
                TextColumn::make('event')->label('Event')->badge()->sortable(),
                TextColumn::make('description')->limit(60)->searchable(),
                TextColumn::make('causer.name')->label('Causer')->placeholder('system'),
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->options(fn (): array => Company::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                SelectFilter::make('log_name')
                    ->label('Model')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),

                SelectFilter::make('event')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformActivityLogs::route('/'),
            'view' => ViewPlatformActivityLog::route('/{record}'),
        ];
    }

    /** An audit trail nobody can write to or edit is the only kind worth having. */
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
