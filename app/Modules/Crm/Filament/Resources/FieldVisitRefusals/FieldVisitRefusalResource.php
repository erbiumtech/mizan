<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisitRefusals;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Crm\Filament\Resources\FieldVisitRefusals\Pages\ListFieldVisitRefusals;
use App\Modules\Crm\Models\FieldVisitRefusal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Clock-ins that were turned away — docs/saas-launch-plan.md §14.10.
 *
 * Read-only, and the point is that the two shapes of problem look nothing alike here.
 * A rep standing in the right place whose phone put them three hundred metres away
 * appears once, with a vague reading; a rep trying from home every morning appears
 * every morning, at the same distance. In an exception log neither appears at all.
 */
class FieldVisitRefusalResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = FieldVisitRefusal::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Audit & Taxes';

    protected static ?string $navigationLabel = 'Refused Check-ins';

    protected static ?string $modelLabel = 'refused check-in';

    protected static ?int $navigationSort = 46;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.user', 'visit']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('employee.display_label')->label(__('Who'))->placeholder('—')->searchable(),
                TextColumn::make('reason')
                    ->badge()
                    ->color(fn (string $state): string => $state === FieldVisitRefusal::REASON_AWAY ? 'danger' : 'warning')
                    ->formatStateUsing(fn (string $state): string => FieldVisitRefusal::REASONS[$state] ?? $state),
                TextColumn::make('distance_m')
                    ->label(__('How far'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state).'m')
                    ->alignRight(),
                TextColumn::make('accuracy_m')
                    ->label(__('Reading'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '±'.number_format($state).'m')
                    ->alignRight()
                    // The column that separates "they were not there" from "the phone could
                    // not tell": a refusal at 300m with a ±500m reading says nothing at all.
                    ->description(fn (FieldVisitRefusal $record): ?string => $record->accuracy_m !== null
                        && $record->distance_m !== null
                        && $record->accuracy_m > $record->distance_m
                        ? 'vaguer than the distance'
                        : null),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('reason')->options(FieldVisitRefusal::REASONS),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has been refused')
            ->emptyStateDescription('Check-ins are only refused when the position check is set to refuse.');
    }

    public static function getPages(): array
    {
        return ['index' => ListFieldVisitRefusals::route('/')];
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
