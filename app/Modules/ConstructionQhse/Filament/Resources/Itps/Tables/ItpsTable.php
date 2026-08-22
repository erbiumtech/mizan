<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps\Tables;

use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Services\ItpService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The register of plans.
 *
 * **Hold points** is the column that matters: it is how much attendance the quality plan is committing the job to, and
 * it is the number somebody should look at before issuing rather than after.
 *
 * Revising is offered rather than editing, because §17.1's whole point is that a plan is a controlled document — an
 * inspection carried out last month has to keep pointing at the document that was in force last month.
 */
class ItpsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Ref')->sortable()->searchable(),

                TextColumn::make('title')
                    ->wrap()
                    ->limit(50)
                    ->searchable()
                    ->description(fn (Itp $record): ?string => $record->job?->code),

                TextColumn::make('discipline')->placeholder('—')->toggleable(),

                TextColumn::make('revision')->label('Rev')->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Itp::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Itp::STATUS_APPROVED => 'success',
                        Itp::STATUS_ISSUED => 'info',
                        Itp::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('activities_count')
                    ->label('Points')
                    ->getStateUsing(fn (Itp $record): int => $record->activities->count())
                    ->alignEnd(),

                /*
                 * How much attendance this plan commits the job to. Worth seeing before issue, not after.
                 */
                TextColumn::make('hold_points')
                    ->label('Hold points')
                    ->badge()
                    ->getStateUsing(fn (Itp $record): ?string => $record->holdPoints() > 0
                        ? (string) $record->holdPoints()
                        : null)
                    ->placeholder('—')
                    ->color('warning')
                    ->tooltip('Points where work may not proceed until somebody attends and releases them.'),

                TextColumn::make('approved_on')->label('Approved')->date('d M Y')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),
                SelectFilter::make('status')->options(Itp::STATUSES),

                Filter::make('in_force')
                    ->label('In force')
                    ->query(fn (Builder $query): Builder => $query->inForce())
                    ->toggle(),
            ])
            ->defaultSort('reference')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Itp $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('An issued plan is frozen — people work to it, and inspections cite it. Issuing refuses a hold point that names nobody: a hold point stops work until somebody attends, so one with no party is a stoppage waiting for no-one.')
                    ->visible(fn (Itp $record): bool => auth()->user()?->can('issue', $record) ?? false)
                    ->action(fn (Itp $record) => static::run(
                        fn () => app(ItpService::class)->issue($record),
                        'Issued.',
                        'The plan is frozen and inspections may cite it.',
                    )),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The state a certification body audits for. Issued first, approved second — approving a draft would approve something still being written.')
                    ->visible(fn (Itp $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(fn (Itp $record) => static::run(
                        fn () => app(ItpService::class)->approve($record),
                        'Approved.',
                        'Your name and the date are on the record.',
                    )),

                Action::make('revise')
                    ->label('Revise')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Copies every point and its parties into a new draft at the next revision letter, and supersedes this one. Past inspections keep pointing at the plan that was in force when they happened, which is the whole reason ITPs carry revisions.')
                    ->visible(fn (Itp $record): bool => auth()->user()?->can('revise', $record) ?? false)
                    ->action(fn (Itp $record) => static::run(
                        fn () => app(ItpService::class)->revise($record),
                        'Revised.',
                        'The new draft is beside this one; this plan is now superseded.',
                    )),

                DeleteAction::make()
                    ->visible(fn (Itp $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No inspection and test plans')
            ->emptyStateDescription('Hold, witness and review are three different commercial positions. An ITP that does not distinguish them is a formality.');
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
