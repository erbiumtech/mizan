<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents\Tables;

use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Services\IncidentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The incident register.
 *
 * **The `Reported` column carries §17.3's own metric.** The delay between something happening and somebody writing it
 * down is a safety measure in its own right: "a site that takes four days to report a first-aid case is a site where the
 * next one is not reported at all."
 *
 * **`Authority` is the exposure**, and the only one in this module with a statutory clock: reportable, and nothing
 * records that anybody told them.
 *
 * The kind badge deliberately does *not* colour a near miss as good news or bad. It is neither — a site reporting many
 * of them is a site where people speak up, and colouring it green would be as wrong as colouring it red.
 */
class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('incident_number')->label('No.')->sortable()->searchable(),

                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Incident::KINDS[$state] ?? $state)
                    ->color(fn (Incident $record): string => match (true) {
                        $record->kind === Incident::KIND_FATALITY => 'danger',
                        $record->isLostTime() => 'danger',
                        $record->isRecordable() => 'warning',
                        // A near miss is neither good nor bad news: a site reporting many is a site where people speak
                        // up, and colouring it either way would misread the number.
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('occurred_at')
                    ->label('Happened')
                    ->dateTime('d M Y H:i')
                    ->description(fn (Incident $record): string => 'hour '.$record->hourOfDay().' of the day')
                    ->sortable(),

                /*
                 * §17.3's own metric, on the face of the register.
                 */
                TextColumn::make('reported_at')
                    ->label('Reported')
                    ->badge()
                    ->getStateUsing(fn (Incident $record): string => match (true) {
                        $record->reportingDelayHours() === null => 'no report time',
                        $record->reportingDelayHours() === 0 => 'same hour',
                        default => $record->reportingDelayHours().' h later',
                    })
                    ->color(fn (Incident $record): string => $record->wasReportedLate() ? 'warning' : 'gray')
                    ->tooltip('The delay between something happening and somebody writing it down is a safety measure in its own right.'),

                TextColumn::make('injured_person_name')
                    ->label('Who')
                    ->getStateUsing(fn (Incident $record): string => $record->personName())
                    ->description(fn (Incident $record): ?string => $record->days_lost
                        ? $record->days_lost.' days lost'
                        : null),

                TextColumn::make('description')->wrap()->limit(50)->searchable()->toggleable(),

                /*
                 * **The exposure**: a statutory duty nobody has discharged.
                 */
                TextColumn::make('authority')
                    ->label('Authority')
                    ->badge()
                    ->getStateUsing(fn (Incident $record): ?string => match (true) {
                        $record->reportableAndUnreported() => 'not reported',
                        $record->reported_to_authority_on !== null => $record->reported_to_authority_on->format('d M Y'),
                        default => null,
                    })
                    ->placeholder('—')
                    ->color(fn (Incident $record): string => $record->reportableAndUnreported() ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Incident::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Incident::STATUS_CLOSED => 'success',
                        Incident::STATUS_UNDER_INVESTIGATION => 'info',
                        default => 'warning',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),
                SelectFilter::make('kind')->options(Incident::KINDS),
                SelectFilter::make('status')->options(Incident::STATUSES),

                Filter::make('lost_time')
                    ->label('Lost time')
                    ->query(fn (Builder $query): Builder => $query->where('is_lost_time', true))
                    ->toggle(),

                Filter::make('near_misses')
                    ->label('Nobody hurt')
                    ->query(fn (Builder $query): Builder => $query->ofKinds(Incident::NO_INJURY_KINDS))
                    ->toggle(),

                Filter::make('unreported')
                    ->label('Reportable, not reported')
                    ->query(fn (Builder $query): Builder => $query->reportable()->whereNull('reported_to_authority_on'))
                    ->toggle(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Incident $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('investigate')
                    ->label('Investigation')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->modalHeading('Record the investigation')
                    ->schema([
                        Textarea::make('immediate_cause')->rows(2),
                        Textarea::make('root_cause')->rows(2),
                        TextInput::make('root_cause_method')->label('How it was found')->maxLength(255),
                        DatePicker::make('investigation_completed_on')->native(false)->default(now()),
                    ])
                    ->visible(fn (Incident $record): bool => auth()->user()?->can('investigate', $record) ?? false)
                    ->action(fn (Incident $record, array $data) => static::run(
                        fn () => app(IncidentService::class)->investigate($record, array_filter(
                            $data,
                            fn ($value): bool => $value !== null && $value !== '',
                        )),
                        'Investigation recorded.',
                        'An incident cannot be closed without a cause against it.',
                    )),

                /*
                 * The statutory duty, discharged and dated.
                 */
                Action::make('reportToAuthority')
                    ->label('Report to authority')
                    ->icon('heroicon-o-megaphone')
                    ->color('danger')
                    ->modalHeading('Record the authority report')
                    ->modalDescription('The date is what a regulator asks for. This is the one clock in this register that cannot be recovered by looking later.')
                    ->schema([
                        TextInput::make('authority')->required()->maxLength(255)->helperText('HSE, OSHA, the labour department.'),
                        TextInput::make('reference')->maxLength(255),
                        DatePicker::make('on')->label('Reported on')->native(false)->default(now())->required(),
                    ])
                    ->visible(fn (Incident $record): bool => auth()->user()?->can('reportToAuthority', $record) ?? false)
                    ->action(fn (Incident $record, array $data) => static::run(
                        fn () => app(IncidentService::class)->reportToAuthority(
                            $record,
                            $data['authority'],
                            $data['reference'] ?? null,
                            $data['on'] ?? null,
                        ),
                        'Recorded.',
                        'The date is on the record.',
                    )),

                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Refused without a cause recorded, and refused while a reportable incident has no authority date against it — closing one would file a statutory duty as finished.')
                    ->visible(fn (Incident $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(fn (Incident $record) => static::run(
                        fn () => app(IncidentService::class)->close($record),
                        'Closed.',
                        'With a cause on the record.',
                    )),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalDescription('New facts do come out. The reason stays on the record so the register says that is what happened.')
                    ->schema([
                        Textarea::make('reason')->rows(2)->required(),
                    ])
                    ->visible(fn (Incident $record): bool => auth()->user()?->can('reopen', $record) ?? false)
                    ->action(fn (Incident $record, array $data) => static::run(
                        fn () => app(IncidentService::class)->reopen($record, $data['reason']),
                        'Reopened.',
                        'The reason is on the record.',
                    )),
            ])
            ->emptyStateHeading('Nothing reported')
            ->emptyStateDescription('Near misses belong here as much as injuries do — near misses per lost-time injury is the number that predicts the next injury.');
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
