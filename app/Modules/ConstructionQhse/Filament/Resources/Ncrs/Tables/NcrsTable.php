<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Tables;

use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Services\NcrService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
 * The NCR register.
 *
 * **The Money column says *proposed*, never *deducted*.** That word is the whole of §17.2: an NCR proposes and the
 * certification service offers the row for somebody to confirm and sign. Once a certificate has taken it up the column
 * says so — and that state is written on the other side of the module boundary, never here.
 *
 * Three other columns earn their place. **Disposition** is "the field that decides whether money changes hands", so its
 * absence is named rather than blank. **CAPA** shows the commonest failure in this whole section: the corrective action
 * done and the preventive action not, which is the pour fixed and the reason it happened left alone. And **Verified**
 * is what makes a closure evidence — a closure with nothing behind it is an assertion, which is what an audit finds.
 */
class NcrsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ncr_number')->label('No.')->sortable()->searchable(),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->searchable()
                    ->description(fn (Ncr $record): ?string => $record->job?->code),

                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Ncr::SEVERITIES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Ncr::SEVERITY_CRITICAL => 'danger',
                        Ncr::SEVERITY_MAJOR => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                /*
                 * §17.2's "field that decides whether money changes hands" — so its absence is named.
                 */
                TextColumn::make('disposition')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? 'not decided'
                        : (Ncr::DISPOSITIONS[$state] ?? $state))
                    ->color(fn (Ncr $record): string => match (true) {
                        $record->disposition === null => 'warning',
                        $record->acceptsNonconformingWork() => 'info',
                        default => 'gray',
                    })
                    ->description(fn (Ncr $record): ?string => $record->concession_reference),

                TextColumn::make('responsible_label')
                    ->label('Who')
                    ->getStateUsing(fn (Ncr $record): string => $record->responsibleName())
                    ->toggleable(),

                /*
                 * **Proposed, never deducted.** And once a human has taken it up, the register says which certificate.
                 */
                TextColumn::make('money')
                    ->label('Money')
                    ->badge()
                    ->getStateUsing(fn (Ncr $record): ?string => match (true) {
                        $record->deductionWasTaken() => 'on a certificate',
                        $record->proposesDeduction() => number_format((float) $record->deduction_amount, 2).' proposed',
                        $record->acceptedWithoutReduction() => 'accepted, nothing proposed',
                        default => null,
                    })
                    ->placeholder('—')
                    ->color(fn (Ncr $record): string => match (true) {
                        $record->deductionWasTaken() => 'success',
                        $record->proposesDeduction() => 'warning',
                        $record->acceptedWithoutReduction() => 'danger',
                        default => 'gray',
                    })
                    ->tooltip('An NCR proposes. The deduction appears on a certificate only when somebody confirms and signs for it.'),

                /*
                 * The commonest CAPA failure: the work fixed and the cause not.
                 */
                TextColumn::make('capa')
                    ->label('CAPA')
                    ->badge()
                    ->getStateUsing(fn (Ncr $record): ?string => match (true) {
                        $record->correctiveOverdue() => 'corrective overdue',
                        $record->fixedButNotPrevented() => 'fixed, not prevented',
                        $record->preventiveOverdue() => 'preventive overdue',
                        default => null,
                    })
                    ->placeholder('—')
                    ->color('warning')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Ncr::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Ncr::STATUS_CLOSED => 'success',
                        Ncr::STATUS_VOID => 'gray',
                        Ncr::STATUS_VERIFIED => 'info',
                        default => 'warning',
                    })
                    ->description(fn (Ncr $record): ?string => $record->void_reason)
                    ->sortable(),

                TextColumn::make('verified_on')
                    ->label('Verified')
                    ->date('d M Y')
                    // Named: a closure with no re-inspection behind it is an assertion.
                    ->placeholder('not verified')
                    ->toggleable(),

                TextColumn::make('days_open')
                    ->label('Days')
                    ->alignEnd()
                    ->getStateUsing(fn (Ncr $record): int => $record->daysOpen())
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),
                SelectFilter::make('severity')->options(Ncr::SEVERITIES),
                SelectFilter::make('status')->options(Ncr::STATUSES),
                SelectFilter::make('disposition')->options(Ncr::DISPOSITIONS),

                Filter::make('live')
                    ->label('Still open')
                    ->query(fn (Builder $query): Builder => $query->live())
                    ->toggle(),

                Filter::make('awaiting_disposition')
                    ->label('Not dispositioned')
                    ->query(fn (Builder $query): Builder => $query->awaitingDisposition())
                    ->toggle(),

                Filter::make('proposing')
                    ->label('Proposing a deduction')
                    ->query(fn (Builder $query): Builder => $query->proposingDeduction())
                    ->toggle(),

                Filter::make('accepted_free')
                    ->label('Accepted with nothing proposed')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('disposition', Ncr::COMMERCIAL_DISPOSITIONS)
                        ->where('deduct_from_payment', false)
                        ->whereNull('back_charge_id'))
                    ->toggle(),
            ])
            ->defaultSort('ncr_number', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Ncr $record): bool => auth()->user()?->can('update', $record) ?? false),

                /*
                 * **The disposition** — ISO 9001's control of nonconforming output, and its own permission.
                 */
                Action::make('disposition')
                    ->label('Disposition')
                    ->icon('heroicon-o-scale')
                    ->color('primary')
                    ->modalHeading('Disposition the nonconformity')
                    ->modalDescription('This is the field that decides whether money changes hands. Use as is and concession requested accept work that does not meet the specification — the client giving something up — and both normally end in a conversation about price.')
                    ->schema([
                        Select::make('disposition')->options(Ncr::DISPOSITIONS)->required()->live(),
                        TextInput::make('concession_reference')
                            ->maxLength(255)
                            ->required(fn (callable $get): bool => $get('disposition') === Ncr::DISPOSITION_CONCESSION)
                            ->visible(fn (callable $get): bool => $get('disposition') === Ncr::DISPOSITION_CONCESSION)
                            ->helperText('What the client actually said, and where. An as-built nobody can defend starts here.'),
                        DatePicker::make('on')->label('Decided on')->native(false)->default(now()),
                    ])
                    ->visible(fn (Ncr $record): bool => auth()->user()?->can('disposition', $record) ?? false)
                    ->action(fn (Ncr $record, array $data) => static::run(
                        fn () => app(NcrService::class)->disposition(
                            $record,
                            $data['disposition'],
                            $data['concession_reference'] ?? null,
                            $data['on'] ?? null,
                        ),
                        'Dispositioned.',
                        'If it accepts nonconforming work, consider what should be withheld for it.',
                    )),

                /*
                 * **Propose a deduction. This withholds nothing.**
                 */
                Action::make('proposeDeduction')
                    ->label('Propose deduction')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->modalHeading('Propose a deduction')
                    ->modalDescription('This withholds nothing. It puts a figure on a list that whoever certifies can take up — and the row appears on a certificate only when they confirm it and their name goes against it. FIDIC 14.6 permits the Engineer to withhold; it does not require it.')
                    ->schema([
                        TextInput::make('amount')->numeric()->required()->label('Amount to propose'),
                        Textarea::make('note')->rows(2)->label('Why'),
                    ])
                    ->visible(fn (Ncr $record): bool => (auth()->user()?->can('proposeDeduction', $record) ?? false)
                        && ! $record->deductionWasTaken())
                    ->action(fn (Ncr $record, array $data) => static::run(
                        fn () => app(NcrService::class)->proposeDeduction(
                            $record,
                            (float) $data['amount'],
                            $data['note'] ?? null,
                        ),
                        'Proposed.',
                        'Nothing has been withheld. It is on the list a certificate can offer.',
                    )),

                Action::make('withdrawDeduction')
                    ->label('Withdraw proposal')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Ncr $record): bool => (auth()->user()?->can('proposeDeduction', $record) ?? false)
                        && $record->proposesDeduction())
                    ->action(fn (Ncr $record) => static::run(
                        fn () => app(NcrService::class)->withdrawDeduction($record),
                        'Withdrawn.',
                        'It is off the list a certificate offers.',
                    )),

                /*
                 * **Verify against a re-inspection** — what makes a closure evidence rather than an assertion.
                 */
                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->modalHeading('Verify against a re-inspection')
                    ->modalDescription('Close-out points at the re-inspection, which is what makes a closure evidence rather than an assertion. It has to be a different inspection from the one that found the nonconformity, and it has to have passed.')
                    ->schema(fn (Ncr $record): array => [
                        Select::make('inspection_id')
                            ->label('Re-inspection')
                            ->options(fn (): array => Inspection::query()
                                ->where('job_id', $record->job_id)
                                ->whereIn('status', Inspection::ACCEPTED)
                                ->whereKeyNot($record->inspection_id ?? 0)
                                ->orderByDesc('inspected_on')
                                ->limit(200)
                                ->get()
                                ->mapWithKeys(fn (Inspection $i): array => [$i->getKey() => $i->displayName()])
                                ->all())
                            ->searchable()
                            ->required(),
                        DatePicker::make('on')->label('Verified on')->native(false),
                    ])
                    ->visible(fn (Ncr $record): bool => (auth()->user()?->can('verify', $record) ?? false)
                        && ! $record->isVerified())
                    ->action(fn (Ncr $record, array $data) => static::run(
                        fn () => app(NcrService::class)->verify(
                            $record,
                            Inspection::query()->findOrFail($data['inspection_id']),
                            $data['on'] ?? null,
                        ),
                        'Verified.',
                        'The re-inspection is on the record, so the closure is evidence.',
                    )),

                Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Refused without a verification. A closure with nothing behind it is an assertion, and an assertion is what an audit finds.')
                    ->visible(fn (Ncr $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(fn (Ncr $record) => static::run(
                        fn () => app(NcrService::class)->close($record),
                        'Closed.',
                        'It keeps its number and its history.',
                    )),

                Action::make('void')
                    ->label('Not a nonconformity')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->modalDescription('Kept as a row with the reason rather than deleted — the number stays in the register, because a gap in a register quoted by number is indistinguishable from a removal somebody wanted.')
                    ->schema([
                        Textarea::make('reason')->label('Why not')->rows(2)->required(),
                    ])
                    ->visible(fn (Ncr $record): bool => auth()->user()?->can('void', $record) ?? false)
                    ->action(fn (Ncr $record, array $data) => static::run(
                        fn () => app(NcrService::class)->void($record, $data['reason']),
                        'Voided.',
                        'The reason is on the row and the number stays used.',
                    )),
            ])
            ->emptyStateHeading('No non-conformances')
            ->emptyStateDescription('An NCR proposes a deduction and never applies one. What moves money is a human putting the row on a certificate and signing for it.');
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
