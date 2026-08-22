<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\RelationManagers;

use App\Modules\ConstructionQhse\Models\SitePersonnel;
use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use App\Modules\ConstructionQhse\Models\ToolboxTalkAttendee;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who was at the talk — §17.5.
 *
 * **Either a register entry or just a name**, and §17.5 requires the second to work alone: "most attendees on most sites
 * are a subcontractor's labourers". The useful *report* is "who on this site has had the working-at-height talk", which
 * needs the register; the useful *form* is one a foreman can fill in at seven in the morning for a gang half of whom
 * arrived that day.
 *
 * **Add everybody on the register** is the convenience that makes this usable at seven in the morning, and it is safe
 * because it copies the names onto the rows — so the sheet still says who was there if the register changes afterwards.
 * Pressing it twice does not double the count.
 */
class AttendeesRelationManager extends RelationManager
{
    protected static string $relationship = 'attendees';

    protected static ?string $title = 'Attendees';

    private function talk(): ToolboxTalk
    {
        /** @var ToolboxTalk $talk */
        $talk = $this->getOwnerRecord();

        return $talk;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('site_personnel_id')
                    ->label('On the register')
                    ->options(fn (): array => static::personnel($this->talk()->job_id))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $person = $state === null ? null : SitePersonnel::query()->find($state);

                        // Snapshotted onto the row, so the sheet keeps saying who was there if the register changes.
                        $set('name', $person?->name);
                        $set('employer', $person?->employer);
                    })
                    ->placeholder('Not on the register'),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('A name is enough. Most people at most talks are somebody else\'s employees.'),

                TextInput::make('employer')->maxLength(255),

                Toggle::make('signed')
                    ->label('Signed the sheet')
                    ->helperText('The difference between a record and a list of names.'),
            ]);
    }

    /** @return array<int, string> */
    private static function personnel(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return SitePersonnel::query()
            ->where('job_id', $jobId)
            ->active()
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (SitePersonnel $person): array => [$person->getKey() => $person->displayName()])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (ToolboxTalkAttendee $record): ?string => $record->employer),

                IconColumn::make('site_personnel_id')
                    ->label('On the register')
                    ->boolean()
                    ->getStateUsing(fn (ToolboxTalkAttendee $record): bool => $record->isOnTheRegister())
                    ->tooltip('A register entry lets "who has had this talk" be answered. A name alone still records that they were there.'),

                IconColumn::make('signed')->label('Signed')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add attendee')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->talk()) ?? false)
                    ->using(fn (array $data): Model => app(SitePersonnelService::class)
                        ->addAttendee($this->talk(), $data)),

                /*
                 * The convenience that makes this usable at seven in the morning.
                 */
                Action::make('addWholeSite')
                    ->label('Add everybody on the register')
                    ->icon('heroicon-o-user-group')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Adds everybody currently on the register for this job, skipping anybody already recorded. Their names are copied onto the sheet, so it keeps saying who was there even if the register changes.')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->talk()) ?? false)
                    ->action(function (): void {
                        $added = app(SitePersonnelService::class)->addWholeSite($this->talk());

                        Notification::make()
                            ->success()
                            ->title($added === 0 ? 'Everybody on the register was already recorded.' : "{$added} added.")
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->talk()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->talk()) ?? false),
            ])
            ->emptyStateHeading('Nobody recorded')
            ->emptyStateDescription('A talk given and not written up is a different fact from a talk nobody came to — and only the first is worth chasing.');
    }
}
