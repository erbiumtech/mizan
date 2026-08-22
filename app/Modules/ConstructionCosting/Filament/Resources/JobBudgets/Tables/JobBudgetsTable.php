<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Tables;

use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Services\BudgetService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * The version register, with the two decisions that are not edits.
 *
 * **Approve** makes a version the current budget, which is what the cost report compares actuals to. **Set
 * baseline** fixes what earned value is measured against for the life of the job. They are separate actions held by
 * separate roles because they are separate decisions — and the second is refused outright once anything has been
 * measured, which is the rule the whole versioning design exists to protect.
 *
 * The *phased* column earns its place: a budget with no month against its lines cannot produce a planned value, so
 * §14's schedule variance reads "unavailable" on the cost report. Showing that here is how somebody finds out why,
 * on the screen where they can do something about it, rather than wondering at the report.
 */
class JobBudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job.code')
                    ->label('Job')
                    ->description(fn (JobBudget $record): ?string => $record->job?->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('version_no')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state))),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        JobBudget::STATUS_APPROVED => 'success',
                        JobBudget::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    }),

                IconColumn::make('is_current')
                    ->label('Current')
                    ->boolean()
                    ->tooltip('What the cost report compares actuals to.'),

                IconColumn::make('is_baseline')
                    ->label('Baseline')
                    ->boolean()
                    ->tooltip('What earned value is measured against. Does not move once work has been measured.'),

                IconColumn::make('phased_lines_count')
                    ->label('Phased')
                    ->boolean()
                    ->state(fn (JobBudget $record): bool => (int) $record->phased_lines_count > 0)
                    // The sentence the cost report would otherwise show without an explanation.
                    ->tooltip(fn (JobBudget $record): string => (int) $record->phased_lines_count > 0
                        ? 'Time-phased, so schedule variance can be computed.'
                        : 'Not time-phased: schedule performance will read "unavailable" on the cost report.')
                    ->toggleable(),

                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('lines_sum_amount')
                    ->label('Budget')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        JobBudget::STATUS_DRAFT => 'Draft',
                        JobBudget::STATUS_APPROVED => 'Approved',
                        JobBudget::STATUS_SUPERSEDED => 'Superseded',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (JobBudget $record): bool => $record->status === JobBudget::STATUS_DRAFT),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve as the current budget')
                    ->modalDescription('The cost report will compare actuals to this version from now on. The version it replaces is kept as superseded so the two can be compared.')
                    ->visible(fn (JobBudget $record): bool => $record->status !== JobBudget::STATUS_APPROVED
                        && (auth()->user()?->can('approve', $record) ?? false))
                    ->action(fn (JobBudget $record) => static::run(
                        fn () => app(BudgetService::class)->approve($record),
                        'Approved.',
                        'This is now the current budget.',
                    )),

                Action::make('setBaseline')
                    ->label('Set baseline')
                    ->icon('heroicon-o-flag')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Set as the earned-value baseline')
                    ->modalDescription('Every earned-value figure on this job is measured against the baseline. Once work has been measured this cannot be changed, because moving it would restate all of them.')
                    ->visible(fn (JobBudget $record): bool => ! $record->is_baseline
                        && (auth()->user()?->can('setBaseline', $record) ?? false))
                    ->action(fn (JobBudget $record) => static::run(
                        fn () => app(BudgetService::class)->setBaseline($record),
                        'Baseline set.',
                        'Earned value on this job is measured against this version.',
                    )),

                Action::make('revise')
                    ->label('Revise')
                    ->icon('heroicon-o-document-duplicate')
                    ->modalHeading('Open a revision')
                    ->modalDescription('A new draft copying this version\'s lines. The baseline stays where it is, which is what keeps earned value comparable across the job.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Version name')
                            ->required()
                            ->helperText('Rev 3 post VO-12, and so on.'),
                    ])
                    ->visible(fn (JobBudget $record): bool => $record->is_current
                        && (auth()->user()?->can('create', JobBudget::class) ?? false))
                    ->action(fn (JobBudget $record, array $data) => static::run(
                        fn () => app(BudgetService::class)->createVersion($record->job, $data['name']),
                        'Revision opened.',
                        'A draft copying this version\'s lines. Approve it when it is ready.',
                    )),
            ]);
    }

    /**
     * Run a service call and turn its refusal into a notification.
     *
     * The refusals here are sentences somebody needs to read — "already has progress measured against baseline
     * Contract award" is the whole explanation — so they are surfaced rather than swallowed or thrown at a
     * stack trace.
     */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
