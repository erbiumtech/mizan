<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities\RelationManagers;

use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\ProgrammeActivityPredecessor;
use App\Modules\ConstructionField\Services\ProgrammeService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What has to happen before this activity — §13.
 *
 * **These links are stored and never solved, and this tab is where that is most tempting to get wrong.** §13:
 * predecessors exist "so an imported network round-trips and a look-ahead can show what is blocking, **and no date is
 * ever calculated from them**". Adding a finish-to-start link with a five-day lag here moves nothing. If the successor's
 * planned start is wrong, it is wrong in P6 too, and it is fixed there and re-imported.
 *
 * What the tab is genuinely for is the **Blocking** column: which of these predecessors is not finished. A look-ahead
 * that says "cladding cannot start" is worth little; one that says "and the two activities in front of it have not
 * started either" is a conversation with the subcontractor.
 */
class PredecessorsRelationManager extends RelationManager
{
    protected static string $relationship = 'predecessors';

    protected static ?string $title = 'Predecessors';

    private function activity(): ProgrammeActivity
    {
        /** @var ProgrammeActivity $activity */
        $activity = $this->getOwnerRecord();

        return $activity;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('predecessor_activity_id')
                    ->label('Has to happen first')
                    ->options(fn (): array => $this->candidates())
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),

                Select::make('relationship')
                    ->options(ProgrammeActivityPredecessor::RELATIONSHIPS)
                    ->default(ProgrammeActivityPredecessor::FINISH_TO_START)
                    ->required(),

                TextInput::make('lag_days')
                    ->label('Lag (days)')
                    ->numeric()
                    ->default(0)
                    ->helperText('A negative lag is a lead — how overlapping trades are programmed. Recorded so the network round-trips; nothing here moves a date because of it.'),
            ]);
    }

    /**
     * The job's other activities.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        return ProgrammeActivity::query()
            ->where('job_id', $this->activity()->job_id)
            ->whereKeyNot($this->activity()->getKey())
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (ProgrammeActivity $activity): array => [$activity->getKey() => $activity->displayName()])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('predecessor.code')
                    ->label('Id')
                    ->sortable(),

                TextColumn::make('predecessor.name')
                    ->label('Activity')
                    ->wrap()
                    ->limit(50),

                TextColumn::make('relationship')
                    ->label('Link')
                    ->badge()
                    ->getStateUsing(fn (ProgrammeActivityPredecessor $record): string => $record->describe())
                    ->tooltip('Stored so an imported network round-trips. No date in this application is calculated from it.'),

                /*
                 * The column the tab exists for: which of these is not finished.
                 */
                TextColumn::make('blocking')
                    ->label('Blocking')
                    ->badge()
                    ->getStateUsing(fn (ProgrammeActivityPredecessor $record): ?string => match (true) {
                        $record->predecessor === null => null,
                        $record->predecessor->isComplete() => null,
                        $record->predecessor->hasStarted() => 'in progress',
                        default => 'not started',
                    })
                    ->placeholder('finished')
                    ->color(fn (ProgrammeActivityPredecessor $record): string => $record->predecessor?->isComplete()
                        ? 'success'
                        : 'danger'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->activity()) ?? false)
                    ->using(function (array $data): Model {
                        $predecessor = ProgrammeActivity::query()->findOrFail($data['predecessor_activity_id']);

                        return app(ProgrammeService::class)->addPredecessor(
                            $this->activity(),
                            $predecessor,
                            $data['relationship'] ?? ProgrammeActivityPredecessor::FINISH_TO_START,
                            (int) ($data['lag_days'] ?? 0),
                        );
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->activity()) ?? false),
            ])
            ->emptyStateHeading('No predecessors recorded')
            ->emptyStateDescription('Links are kept so an imported programme round-trips, and so a look-ahead can say what is blocking. They never move a date.');
    }
}
