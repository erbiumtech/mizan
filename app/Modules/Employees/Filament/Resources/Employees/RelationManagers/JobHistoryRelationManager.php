<?php

namespace App\Modules\Employees\Filament\Resources\Employees\RelationManagers;

use App\Modules\Employees\Models\EmployeeJobHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What this person's job has been, and when each change took effect — `docs/hrms-plan.md`, the one thing
 * that section records as not built: *"Not built: any UI for the history itself."*
 *
 * **Read-only, and that is the design rather than a shortcut.** The plan's own next sentence says so: the
 * rows accumulate from the ordinary employee form, so "the natural next step is a read-only relation
 * manager on `ViewEmployee`, not a resource — job history is written by a change, never typed." A form here
 * would let somebody record a promotion that never happened to the employee record, and the two would
 * disagree with nothing to say which was right.
 *
 * Newest first, which is the order the relation itself declares: the question this tab answers is usually
 * "what changed recently", and the joining row is at the bottom where a CV puts it.
 */
class JobHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'jobHistory';

    protected static ?string $title = 'Job history';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('designation')
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('From')
                    ->date('d M Y')
                    ->sortable()
                    // A change agreed now and effective next month is a real row that has not happened yet,
                    // and the badge is the only thing on the screen that says so.
                    ->badge()
                    ->color(fn (EmployeeJobHistory $record): string => $record->effective_from?->isFuture() ? 'warning' : 'gray')
                    ->description(fn (EmployeeJobHistory $record): ?string => $record->effective_from?->isFuture()
                        ? 'Takes effect on this date'
                        : null),

                TextColumn::make('designation')->placeholder('—'),
                TextColumn::make('department')->placeholder('—'),

                TextColumn::make('employment_type')
                    ->label('Employment type')
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->headline()->value() : '—'),

                // The manager as at that change, not the manager today: reading the current record here
                // would rewrite every historical row whenever somebody's reporting line moves.
                TextColumn::make('manager.display_label')
                    ->label('Reported to')
                    ->placeholder('—'),

                TextColumn::make('reason')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),

                // `recorded_by` is deliberately not a column here. It holds a *landlord* user id — users are
                // shared across companies — so showing the name means a cross-database lookup per row, which
                // is real machinery for a fact nobody asked to see on this tab. The audit log has it.
            ])
            ->emptyStateHeading('No job changes recorded')
            ->emptyStateDescription('A row is written here whenever this employee\'s designation, department, '
                .'employment type or manager changes on their record.');
    }
}
