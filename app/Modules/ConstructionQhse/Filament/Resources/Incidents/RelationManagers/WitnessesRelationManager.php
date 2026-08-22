<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents\RelationManagers;

use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Models\IncidentWitness;
use App\Modules\ConstructionQhse\Services\IncidentService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Witnesses — §17.3.
 *
 * **The date the statement was taken is the column that matters.** A statement taken on the day is worth a multiple of
 * one taken three weeks later, and an investigation that cannot say when it spoke to somebody is an investigation nobody
 * can weigh. The table shows the gap in days rather than leaving a reader to work it out.
 *
 * **The name works alone**, because most witnesses on most sites are somebody else's employees — and a register that
 * required an employee record would record the witnesses who happened to be on the payroll, which is not the same set as
 * the witnesses.
 */
class WitnessesRelationManager extends RelationManager
{
    protected static string $relationship = 'witnesses';

    protected static ?string $title = 'Witnesses';

    private function incident(): Incident
    {
        /** @var Incident $incident */
        $incident = $this->getOwnerRecord();

        return $incident;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('employer')->maxLength(255),
                TextInput::make('contact_detail')->label('How to reach them')->maxLength(255),
                DatePicker::make('statement_taken_on')
                    ->label('Statement taken on')
                    ->native(false)
                    ->helperText('Filled in for you when you type a statement. A statement taken on the day is worth several taken three weeks later.'),
                Textarea::make('statement')->rows(4)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (IncidentWitness $record): ?string => $record->employer),

                TextColumn::make('statement')
                    ->wrap()
                    ->limit(60)
                    // Named rather than blank: a witness with no statement is somebody nobody has spoken to.
                    ->placeholder('no statement taken'),

                TextColumn::make('statement_taken_on')
                    ->label('Taken')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->description(fn (IncidentWitness $record): ?string => match (true) {
                        ! $record->hasStatement() => null,
                        $record->daysAfterIncident() === 0 => 'on the day',
                        $record->daysAfterIncident() === null => null,
                        default => $record->daysAfterIncident().' days after',
                    })
                    ->color(fn (IncidentWitness $record): string => ($record->daysAfterIncident() ?? 0) > 7
                        ? 'warning'
                        : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->incident()) ?? false)
                    ->using(fn (array $data): Model => app(IncidentService::class)
                        ->addWitness($this->incident(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->incident()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->incident()) ?? false),
            ])
            ->emptyStateHeading('No witnesses recorded')
            ->emptyStateDescription('A name is enough. Most witnesses on most sites are somebody else\'s employees.');
    }
}
