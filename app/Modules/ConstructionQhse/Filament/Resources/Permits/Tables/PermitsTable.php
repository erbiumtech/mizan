<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Permits\Tables;

use App\Modules\ConstructionQhse\Models\Permit;
use App\Modules\ConstructionQhse\Services\PermitService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The permit register.
 *
 * **The `Window` column is the register.** §17.5: "a permit is time-boxed, and an expired-but-open permit is the failure
 * mode that kills people." So the column says *expired* in red for anything past its end and still open, hours remaining
 * for anything live, and nothing for what is closed.
 *
 * **`Accepted` earns its place next to it.** A permit issued and never accepted by whoever is doing the work is a piece
 * of paper rather than an authorisation, and the gap only exists because both stamps are kept.
 *
 * **Nothing here closes a permit automatically.** Expiry makes it visible and a person closes it — a permit quietly
 * marked closed by a scheduled job is a hazard nobody walked back to.
 */
class PermitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('permit_number')
                    ->label('No.')
                    ->sortable()
                    ->searchable()
                    ->description(fn (Permit $record): ?string => $record->isExtension()
                        // Named, so an extension is never mistaken for a fresh authorisation.
                        ? 'extends '.$record->extends?->permit_number
                        : null),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Permit::TYPES[$state] ?? $state)
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(50)
                    ->searchable()
                    ->description(fn (Permit $record): ?string => $record->location?->fullName()
                        ?? $record->location_detail),

                /*
                 * **The column the register exists for.**
                 */
                TextColumn::make('window')
                    ->label('Window')
                    ->badge()
                    ->getStateUsing(fn (Permit $record): string => match (true) {
                        $record->isExpiredAndOpen() => 'EXPIRED, still open',
                        $record->isClosed() => $record->windowHours().' h authorised',
                        $record->hoursRemaining() < 0 => 'expired',
                        default => $record->hoursRemaining().' h left',
                    })
                    ->color(fn (Permit $record): string => match (true) {
                        $record->isExpiredAndOpen() => 'danger',
                        $record->isClosed() => 'gray',
                        $record->hoursRemaining() < 4 => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Permit $record): string => $record->valid_from->format('d M H:i')
                        .' → '.$record->valid_to->format('d M H:i'))
                    ->tooltip('An expired permit that nobody has closed is the failure mode this register exists to make visible.'),

                TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime('d M H:i')
                    // Named: a permit nobody accepted is a piece of paper rather than an authorisation.
                    ->placeholder(fn (Permit $record): string => $record->issuedButNotAccepted() ? 'not accepted' : '—')
                    ->color(fn (Permit $record): string => $record->issuedButNotAccepted() ? 'warning' : 'gray')
                    ->description(fn (Permit $record): ?string => $record->accepted_by_label)
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Permit::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Permit::STATUS_ISSUED => 'success',
                        Permit::STATUS_SUSPENDED => 'warning',
                        Permit::STATUS_CLOSED => 'gray',
                        Permit::STATUS_CANCELLED => 'gray',
                        default => 'info',
                    })
                    ->description(fn (Permit $record): ?string => $record->suspension_reason ?? $record->cancel_reason)
                    ->sortable(),

                TextColumn::make('area_made_safe')
                    ->label('Made safe')
                    ->badge()
                    ->getStateUsing(fn (Permit $record): ?string => match (true) {
                        ! $record->isClosed() => null,
                        $record->area_made_safe => 'yes',
                        default => 'not recorded',
                    })
                    ->placeholder('—')
                    ->color(fn (Permit $record): string => $record->isClosed() && ! $record->area_made_safe
                        ? 'warning'
                        : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),
                SelectFilter::make('type')->options(Permit::TYPES),
                SelectFilter::make('status')->options(Permit::STATUSES),

                Filter::make('expired_open')
                    ->label('Expired and still open')
                    ->query(fn (Builder $query): Builder => $query->expiredAndOpen())
                    ->toggle(),

                Filter::make('in_force')
                    ->label('Authorising work now')
                    ->query(fn (Builder $query): Builder => $query->inForceAt())
                    ->toggle(),

                Filter::make('not_accepted')
                    ->label('Issued, not accepted')
                    ->query(fn (Builder $query): Builder => $query->authorising()
                        ->whereNotNull('issued_at')
                        ->whereNull('accepted_at'))
                    ->toggle(),
            ])
            ->defaultSort('valid_to', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('update', $record) ?? false),

                /*
                 * **The act that authorises high-risk work**, and its own permission.
                 */
                Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Issue the permit')
                    ->modalDescription('This authorises the work. It is refused for a window that has already closed, and refused until the controls this type turns on are recorded — a confined space with no rescue plan, hot work with no fire watch.')
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('issue', $record) ?? false)
                    ->action(fn (Permit $record) => static::run(
                        fn () => app(PermitService::class)->issue($record),
                        'Issued.',
                        'Your name is on the authorisation.',
                    )),

                Action::make('accept')
                    ->label('Record acceptance')
                    ->icon('heroicon-o-hand-raised')
                    ->color('info')
                    ->modalDescription('A permit nobody accepted is a piece of paper rather than an authorisation.')
                    ->schema([
                        TextInput::make('accepted_by')->label('Accepted by')->required()->maxLength(255),
                        DateTimePicker::make('at')->label('At')->seconds(false)->default(now()),
                    ])
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('accept', $record) ?? false)
                    ->action(fn (Permit $record, array $data) => static::run(
                        fn () => app(PermitService::class)->accept($record, $data['accepted_by'], $data['at'] ?? null),
                        'Acceptance recorded.',
                        'The permit is an authorisation both sides have signed.',
                    )),

                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->modalDescription('Stops the work without ending the authorisation. The reason stays on the record, because whoever comes back to it needs to know what has to change before work restarts.')
                    ->schema([
                        Textarea::make('reason')->rows(2)->required(),
                    ])
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('suspend', $record) ?? false)
                    ->action(fn (Permit $record, array $data) => static::run(
                        fn () => app(PermitService::class)->suspend($record, $data['reason']),
                        'Suspended.',
                        'Work is stopped and the reason is on the record.',
                    )),

                Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Refused once the window has closed: resuming then would authorise work outside what somebody signed for. Extend it instead, which records a new authorisation rather than stretching the old one.')
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('resume', $record) ?? false)
                    ->action(fn (Permit $record) => static::run(
                        fn () => app(PermitService::class)->resume($record),
                        'Resumed.',
                        'Inside the original window.',
                    )),

                /*
                 * **An extension is a new row.** Never a mutated end time.
                 */
                Action::make('extend')
                    ->label('Extend')
                    ->icon('heroicon-o-clock')
                    ->color('primary')
                    ->modalHeading('Extend the permit')
                    ->modalDescription('This creates a new permit starting where this one ends, and closes this one. Overwriting the end time would destroy the record of what was authorised when — which is the question a regulator asks after something happens.')
                    ->schema([
                        DateTimePicker::make('valid_to')
                            ->label('New end of the window')
                            ->seconds(false)
                            ->required(),
                        Textarea::make('reason')->rows(2),
                    ])
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('extend', $record) ?? false)
                    ->action(fn (Permit $record, array $data) => static::run(
                        fn () => app(PermitService::class)->extend($record, $data['valid_to'], $data['reason'] ?? null),
                        'Extension raised as a draft.',
                        'It still has to be issued — an extension is a fresh authorisation, not a stretched one.',
                    )),

                Action::make('close')
                    ->label('Close out')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->modalHeading('Close the permit out')
                    ->modalDescription('The area made safe is the point of the close-out. A permit closed with nobody having walked the area is the sequence that burns a building down an hour after everybody has gone home — so closing without it needs a sentence saying why.')
                    ->schema([
                        Toggle::make('area_made_safe')->label('The area was walked and made safe')->default(true)->live(),
                        Textarea::make('notes')
                            ->rows(2)
                            ->required(fn (callable $get): bool => ! $get('area_made_safe'))
                            ->label('Close-out notes'),
                    ])
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(fn (Permit $record, array $data) => static::run(
                        fn () => app(PermitService::class)->close(
                            $record,
                            (bool) ($data['area_made_safe'] ?? false),
                            $data['notes'] ?? null,
                        ),
                        'Closed out.',
                        'Off the expired-and-open list.',
                    )),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->schema([
                        Textarea::make('reason')->rows(2)->required(),
                    ])
                    ->visible(fn (Permit $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->action(fn (Permit $record, array $data) => static::run(
                        fn () => app(PermitService::class)->cancel($record, $data['reason']),
                        'Cancelled.',
                        'The reason is on the record.',
                    )),
            ])
            ->emptyStateHeading('No permits')
            ->emptyStateDescription('A permit is time-boxed. The register\'s first duty is to show what is past its window and still open.');
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
