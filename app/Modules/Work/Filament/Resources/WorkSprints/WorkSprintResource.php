<?php

namespace App\Modules\Work\Filament\Resources\WorkSprints;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Resources\WorkSprints\Pages\ListWorkSprints;
use App\Modules\Work\Filament\Resources\WorkSprints\Pages\ViewWorkSprint;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Services\Sprints;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * Sprints — docs/project-management-plan.md §6.2.
 *
 * Two buttons carry the whole lifecycle, and the second is the consequential one: completing a
 * sprint decides where everything unfinished in it goes, and that is asked rather than assumed.
 */
class WorkSprintResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = WorkSprint::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Sprints');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->label(__('Project'))
                ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->disabledOn('edit'),

            TextInput::make('name')->required()->maxLength(80)->placeholder('Sprint 14'),

            TextInput::make('goal')
                ->maxLength(255)
                ->placeholder('The export works on Safari and the invoice run is unattended')
                ->helperText('One sentence. A sprint with no goal is a list of tickets with a date on it.'),

            DatePicker::make('starts_on')->native(false)->required(),
            DatePicker::make('ends_on')->native(false)->required()->afterOrEqual('starts_on'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project')->withCount('items'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (WorkSprint $record): ?string => $record->goal),

                TextColumn::make('project.name')->label(__('Project'))->toggleable(),

                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => WorkSprint::STATES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        WorkSprint::ACTIVE => 'success',
                        WorkSprint::CLOSED => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('starts_on')->date('d M Y')->label(__('From'))->sortable(),
                TextColumn::make('ends_on')->date('d M Y')->label(__('To'))->sortable(),
                TextColumn::make('items_count')->label(__('Items'))->alignEnd(),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                SelectFilter::make('state')->options(WorkSprint::STATES),
                SelectFilter::make('project_id')->label(__('Project'))->relationship('project', 'name'),
            ])
            ->recordActions([
                Action::make('start')
                    ->label(__('Start'))
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (WorkSprint $record): bool => ! $record->isActive() && ! $record->isClosed()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Only one sprint runs at a time in a project.')
                    ->action(function (WorkSprint $record): void {
                        try {
                            app(Sprints::class)->start($record);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('It did not start')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($record->name.' is running.')->send();
                    }),

                /*
                 * Completing, which is where the unfinished work is decided.
                 *
                 * The form asks rather than defaulting, because "into the next sprint" and "back to
                 * the backlog" are different statements about whether the team still intends to do
                 * it, and a tool that picks one silently is making that call for them.
                 */
                Action::make('complete')
                    ->label(__('Complete'))
                    ->icon('heroicon-o-flag')
                    ->color('warning')
                    ->visible(fn (WorkSprint $record): bool => $record->isActive()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema(fn (WorkSprint $record): array => [
                        Select::make('move_to')
                            ->label(__('Unfinished work goes to'))
                            ->options(fn (): array => WorkSprint::query()
                                ->where('project_id', $record->project_id)
                                ->where('state', '!=', WorkSprint::CLOSED)
                                ->whereKeyNot($record->getKey())
                                ->orderBy('starts_on')
                                ->pluck('name', 'id')
                                ->all())
                            ->placeholder('Back to the backlog')
                            ->helperText(fn (): string => app(Sprints::class)->unfinished($record)->count()
                                .' item(s) are still open in this sprint.'),
                    ])
                    ->action(function (WorkSprint $record, array $data): void {
                        $target = filled($data['move_to'] ?? null)
                            ? WorkSprint::query()->find($data['move_to'])
                            : null;

                        try {
                            app(Sprints::class)->complete($record, $target);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('It is still running')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title($record->name.' is closed.')
                            ->body('Unfinished work went '.($target !== null ? 'to '.$target->name : 'back to the backlog').'.')
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkSprints::route('/'),
            'view' => ViewWorkSprint::route('/{record}'),
        ];
    }
}
