<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Inspections\Tables;

use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Services\InspectionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The inspection register.
 *
 * **Two columns carry it, and both exist because §17.1 refuses to treat a hold point as a checkbox.**
 *
 * *Work* says whether this inspection is still stopping something: a hold point that has not been released blocks the
 * next operation whatever its result, and a hold point that *passed* and has not been released is work standing still
 * for want of a signature nobody knows is missing.
 *
 * *Witness* is the contractor's protection: invited, did not attend, work proceeded lawfully. Nobody writes that down at
 * the time, and it is the fact that decides an argument two months later.
 */
class InspectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Ref')->sortable()->searchable(),

                TextColumn::make('activity_description')
                    ->label('Inspection')
                    ->wrap()
                    ->limit(50)
                    ->searchable()
                    ->description(fn (Inspection $record): ?string => $record->isAdHoc()
                        // Named, because an ad-hoc inspection cites no controlled document and an auditor asks.
                        ? 'ad hoc — no ITP point'
                        : $record->itpActivity?->itp?->reference),

                TextColumn::make('point_type')
                    ->label('Point')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ItpActivity::POINT_HOLD => 'Hold',
                        ItpActivity::POINT_WITNESS => 'Witness',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        ItpActivity::POINT_HOLD => 'danger',
                        ItpActivity::POINT_WITNESS => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('location.name')
                    ->label('Where')
                    ->getStateUsing(fn (Inspection $record): ?string => $record->location?->fullName())
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('requested_on')->label('Requested')->date('d M Y')->sortable(),

                TextColumn::make('inspected_on')
                    ->label('Inspected')
                    ->date('d M Y')
                    ->placeholder('not yet')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Inspection::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Inspection::STATUS_PASSED => 'success',
                        Inspection::STATUS_PASSED_WITH_COMMENTS => 'info',
                        Inspection::STATUS_FAILED => 'danger',
                        Inspection::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                /*
                 * **Is this still stopping work?** The register's reason for existing.
                 */
                TextColumn::make('work')
                    ->label('Work')
                    ->badge()
                    ->getStateUsing(fn (Inspection $record): ?string => match (true) {
                        $record->awaitingRelease() => 'awaiting release',
                        $record->blocksWork() => 'held',
                        $record->isHoldPoint() => 'released',
                        default => null,
                    })
                    ->placeholder('—')
                    ->color(fn (Inspection $record): string => match (true) {
                        $record->awaitingRelease() => 'danger',
                        $record->blocksWork() => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Inspection $record): ?string => $record->daysAwaitingRelease() > 0
                        ? $record->daysAwaitingRelease().' days'
                        : null)
                    ->tooltip('A hold point that has passed and not been released is work standing still for want of a signature.'),

                /*
                 * The contractor's protection at a witness point.
                 */
                TextColumn::make('witness')
                    ->label('Witness')
                    ->badge()
                    ->getStateUsing(fn (Inspection $record): ?string => match (true) {
                        $record->witnessFailedToAttend() => 'invited, absent',
                        $record->witness_attended => 'attended',
                        default => null,
                    })
                    ->placeholder('—')
                    ->color(fn (Inspection $record): string => $record->witnessFailedToAttend() ? 'warning' : 'gray')
                    ->description(fn (Inspection $record): ?string => $record->noticeGivenInFull() === false
                        // Said out loud: short notice is the other side's first answer to "you proceeded without us".
                        ? 'short notice'
                        : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),
                SelectFilter::make('status')->options(Inspection::STATUSES),
                SelectFilter::make('point_type')->options(ItpActivity::POINT_TYPES),

                Filter::make('awaiting_release')
                    ->label('Hold points awaiting release')
                    ->query(fn (Builder $query): Builder => $query->awaitingRelease())
                    ->toggle(),

                Filter::make('blocking')
                    ->label('Hold points not yet inspected')
                    ->query(fn (Builder $query): Builder => $query->holdPoints()->open())
                    ->toggle(),

                Filter::make('witnessed_in_absence')
                    ->label('Witness invited and absent')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('point_type', ItpActivity::POINT_WITNESS)
                        ->whereNotNull('notified_on')
                        ->whereNotNull('inspected_on')
                        ->where('witness_attended', false))
                    ->toggle(),
            ])
            ->defaultSort('requested_on', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Inspection $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('notify')
                    ->label('Record notice')
                    ->icon('heroicon-o-bell')
                    ->color('info')
                    ->modalHeading('Record that the attending party was told')
                    ->modalDescription('Separate from the request date, because a notice period runs from when the other party was told — and at a witness point that date is the whole of your protection for proceeding without them.')
                    ->schema([
                        DatePicker::make('on')->label('Told on')->native(false)->default(now())->required(),
                        DatePicker::make('scheduled_for')->label('Scheduled for')->native(false),
                    ])
                    ->visible(fn (Inspection $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && $record->isOpen())
                    ->action(fn (Inspection $record, array $data) => static::run(
                        fn () => app(InspectionService::class)->notify($record, $data['on'], $data['scheduled_for'] ?? null),
                        'Notice recorded.',
                        'The notice period runs from this date.',
                    )),

                Action::make('record')
                    ->label('Record result')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->modalHeading('Record what was found')
                    ->modalDescription('This never releases a hold point. Recording that the work is right and authorising the next operation to start are two decisions — and on a certified site they are two people.')
                    ->schema([
                        Select::make('status')
                            ->label('Result')
                            ->options([
                                Inspection::STATUS_PASSED => 'Passed',
                                Inspection::STATUS_PASSED_WITH_COMMENTS => 'Passed with comments',
                                Inspection::STATUS_FAILED => 'Failed',
                                Inspection::STATUS_CANCELLED => 'Cancelled',
                            ])
                            ->required(),
                        DatePicker::make('inspected_on')->label('Inspected on')->native(false)->default(now())->required(),
                        Toggle::make('witness_attended')
                            ->label('The witness attended')
                            ->helperText('Leave it off if they were invited and did not come — that is what lets work proceed past a witness point, and it is the fact nobody writes down.'),
                        Textarea::make('result_notes')->rows(3),
                    ])
                    ->visible(fn (Inspection $record): bool => auth()->user()?->can('record', $record) ?? false)
                    ->action(fn (Inspection $record, array $data) => static::run(
                        fn () => app(InspectionService::class)->record($record, $data),
                        'Result recorded.',
                        'A hold point still needs releasing before work may proceed.',
                    )),

                /*
                 * **The release.** Its own action, its own permission, and its own refusals — see `InspectionService`.
                 */
                Action::make('release')
                    ->label('Release hold point')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->modalHeading('Release the hold point')
                    ->modalDescription('This authorises the next operation to start. It is the act a certification body audits, and it is deliberately not the same act as recording that the inspection passed.')
                    ->schema([
                        Textarea::make('notes')->label('Release notes')->rows(2),
                    ])
                    ->visible(fn (Inspection $record): bool => auth()->user()?->can('release', $record) ?? false)
                    ->action(fn (Inspection $record, array $data) => static::run(
                        fn () => app(InspectionService::class)->release($record, $data['notes'] ?? null),
                        'Hold point released.',
                        'Work may proceed, with your name against it.',
                    )),

                DeleteAction::make()
                    ->visible(fn (Inspection $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No inspections')
            ->emptyStateDescription('A hold point that releases nothing and blocks nothing is a checkbox with extra steps. This register is where it releases something.');
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
