<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs\RelationManagers;

use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\ConstructionQhse\Services\ActionService;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Actions raised against this NCR — §17.4.
 *
 * **This is where an action is raised, and the register is where it is read.** §17.4 puts every QHSE object's actions in
 * one table so the safety manager has one overdue list; the tab exists because an action with no finding behind it is a
 * task in a quality register, and the finding is here.
 *
 * Note what this tab is *not*: it is not the NCR's CAPA. §17.2 puts corrective and preventive action on the NCR itself
 * because ISO 9001 asks for them there, and those fields are on the form. These are the working tasks, and the two are
 * **deliberately not mirrored** — a mirror is two sources for one date. The combined overdue list assembles both and
 * says which source each row came from.
 */
class ActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'actions';

    protected static ?string $title = 'Actions';

    private function ncr(): Ncr
    {
        /** @var Ncr $ncr */
        $ncr = $this->getOwnerRecord();

        return $ncr;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Textarea::make('description')->required()->rows(2)->columnSpanFull(),

                Select::make('action_type')
                    ->options(QhseAction::TYPES)
                    ->default('corrective')
                    ->required(),

                Select::make('priority')->options(QhseAction::PRIORITIES)->default('medium')->required(),

                DatePicker::make('due_on')->label('Due')->native(false),

                TextInput::make('assignee_label')
                    ->label('Who')
                    ->maxLength(255)
                    ->helperText('A name is enough. An action nobody is assigned to is an action nobody does.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('description')->wrap()->limit(60),

                TextColumn::make('action_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                TextColumn::make('assignee_label')
                    ->label('Who')
                    ->getStateUsing(fn (QhseAction $record): string => $record->assigneeName()),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date('d M Y')
                    ->placeholder('no date set')
                    ->color(fn (QhseAction $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QhseAction::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        QhseAction::STATUS_VERIFIED => 'success',
                        QhseAction::STATUS_DONE => 'info',
                        default => 'warning',
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('ConstructionActionUpdate') ?? false)
                    ->using(fn (array $data): Model => app(ActionService::class)->raise($this->ncr(), $data)),
            ])
            ->emptyStateHeading('No actions raised')
            ->emptyStateDescription('The CAPA fields on the NCR are the record ISO 9001 asks for. These are the working tasks, and they appear in the one overdue list with everything else.');
    }
}
