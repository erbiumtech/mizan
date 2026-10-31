<?php

namespace App\Modules\Performance\Filament\Resources\TaskOccurrences;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Support\RecordSheet;
use App\Modules\Performance\Filament\Resources\TaskOccurrences\Pages\ListTaskOccurrences;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Services\TaskRoller;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * Tasks as they fall due — docs/saas-launch-plan.md §8.2. Your own, and your
 * downline's; the two things a person does to one are complete and excuse.
 */
class TaskOccurrenceResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = TaskOccurrence::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    protected static ?int $navigationSort = 61;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['task', 'employee.user']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('task.title')->label(__('Task'))->searchable(),
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('due_at')->dateTime('d M H:i')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (TaskOccurrence $record): string => $record->status())
                    ->color(fn (string $state): string => match ($state) {
                        TaskOccurrence::STATUS_COMPLETED => 'success',
                        TaskOccurrence::STATUS_MISSED => 'danger',
                        TaskOccurrence::STATUS_EXCUSED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('task.penalty_points')->label(__('Points'))->alignEnd(),
                TextColumn::make('completed_note')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('excused_reason')->limit(40)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('due_at', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->options([
                        TaskOccurrence::STATUS_OPEN => 'Open',
                        TaskOccurrence::STATUS_COMPLETED => 'Completed',
                        TaskOccurrence::STATUS_MISSED => 'Missed',
                        TaskOccurrence::STATUS_EXCUSED => 'Excused',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        TaskOccurrence::STATUS_OPEN => $query->open(),
                        TaskOccurrence::STATUS_COMPLETED => $query->whereNotNull('completed_at'),
                        TaskOccurrence::STATUS_MISSED => $query->missedUnexcused(),
                        TaskOccurrence::STATUS_EXCUSED => $query->whereNotNull('excused_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label(__('Done'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TaskOccurrence $record): bool => $record->isOpen()
                        && (auth()->user()?->can('complete', $record) ?? false))
                    ->schema([Textarea::make('note')->rows(2)->maxLength(500)])
                    ->action(function (TaskOccurrence $record, array $data): void {
                        try {
                            app(TaskRoller::class)->complete($record, $data['note'] ?? null, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Done.')->send();
                    }),
                Action::make('excuse')
                    ->label(__('Excuse'))
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->visible(fn (TaskOccurrence $record): bool => $record->status() === TaskOccurrence::STATUS_MISSED
                        && (auth()->user()?->can('excuse', $record) ?? false))
                    ->schema([Textarea::make('reason')->rows(2)->required()->maxLength(200)])
                    ->modalDescription('The miss stays on the record; the points it cost are reversed on the ledger.')
                    ->action(function (TaskOccurrence $record, array $data): void {
                        try {
                            app(TaskRoller::class)->excuse($record, auth()->user(), (string) $data['reason']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Excused.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTaskOccurrences::route('/')];
    }
}
