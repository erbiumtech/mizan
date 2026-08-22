<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers;

use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogEvent;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\ConstructionField\Services\DelayEventService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * What happened on the day, and whether anybody has notified it — §16.1 and §13.
 *
 * **This tab is where the diary and the notice clock meet, and it is the most valuable thing on the screen.** A diary
 * line that cost time and has no delay event behind it is money the company has lost and does not yet know it has
 * lost — §13's "absolute silence", written down on the day and then forgotten. The *Notified* column asks the question
 * and *Raise delay event* answers it in one click, carrying the description and the date across so the notice clock
 * starts from the day the thing happened rather than the day somebody noticed.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Events';

    private function log(): DailyLog
    {
        /** @var DailyLog $log */
        $log = $this->getOwnerRecord();

        return $log;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')
                    ->options(DailyLogEvent::KINDS)
                    ->required()
                    ->searchable(),

                Select::make('responsibility')
                    ->options(DailyLogEvent::RESPONSIBILITIES)
                    ->default('neutral')
                    ->selectablePlaceholder(false)
                    ->required()
                    // Recorded on the day, because the diary is the only document written while anybody remembers.
                    ->helperText('Write it down now. An event with nothing against it is the one assigned to you by default six months later.'),

                TimePicker::make('started_at')->seconds(false),
                TimePicker::make('ended_at')->seconds(false),

                TextInput::make('hours_lost')
                    ->numeric()
                    ->default(0)
                    ->helperText('The figure a claim is made of. Zero where nothing was lost.'),

                TextInput::make('raised_with')
                    ->label('Told to')
                    ->maxLength(255)
                    ->helperText('Who on the other side was told, on the day.'),

                Textarea::make('description')
                    ->rows(3)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DailyLogEvent::KINDS[$state] ?? $state),

                TextColumn::make('description')->wrap()->limit(80),

                TextColumn::make('responsibility')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'employer' => 'success',
                        'contractor' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextColumn::make('hours_lost')
                    ->label('Hours lost')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Lost')),

                /*
                 * **The column the tab exists for.** Not a dash when unnotified: a blank cell reads as "nothing to do",
                 * and this is the one that costs money.
                 */
                TextColumn::make('notified')
                    ->label('Notified')
                    ->badge()
                    ->getStateUsing(fn (DailyLogEvent $record): string => $record->isNotified()
                        ? ($record->delayEvent?->reference ?? 'Yes')
                        : ((float) $record->hours_lost > 0 && $record->responsibility !== 'contractor'
                            ? 'NOT NOTIFIED'
                            : '—'))
                    ->color(fn (string $state): string => $state === 'NOT NOTIFIED' ? 'danger' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false)
                    ->using(fn (array $data): Model => app(DailyLogService::class)->addEvent($this->log(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),

                Action::make('raiseDelayEvent')
                    ->label('Raise delay event')
                    ->icon('heroicon-o-megaphone')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Start the notice clock on this')
                    ->modalDescription('Raises a delay event dated the day this happened, not today — the notice period runs from the event. The diary line then points at it, so the register stops reporting it as unnotified.')
                    ->schema([
                        Select::make('cause_category')
                            ->label('Cause')
                            ->options(DelayEvent::CAUSES)
                            ->required()
                            ->searchable(),
                    ])
                    ->visible(fn (DailyLogEvent $record): bool => ! $record->isNotified()
                        && (auth()->user()?->can('create', DelayEvent::class) ?? false))
                    ->action(function (DailyLogEvent $record, array $data): void {
                        try {
                            $event = app(DelayEventService::class)->raise($this->log()->job, [
                                'title' => str($record->description)->limit(120)->toString(),
                                'description' => $record->description,
                                'cause_category' => $data['cause_category'],
                                // The day it happened, which is what the notice period runs from.
                                'occurred_on' => $this->log()->log_date->toDateString(),
                            ]);

                            $record->update(['delay_event_id' => $event->getKey()]);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title("{$event->reference} raised.")
                            ->body('Notice is due by '.$event->notice_required_by->format('d M Y').'.')
                            ->send();
                    }),

                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
            ])
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('Delays, instructions, stoppages, visitors, inspections. An event that cost time and has no delay event behind it is money nobody has claimed for.');
    }
}
