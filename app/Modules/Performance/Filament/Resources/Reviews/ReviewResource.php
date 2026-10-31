<?php

namespace App\Modules\Performance\Filament\Resources\Reviews;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\CreateReview;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\EditReview;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\ListReviews;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Models\ReviewTemplate;
use App\Modules\Performance\Services\ReviewCycleService;
use App\Modules\Performance\Services\ReviewTemplates;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * One person's review.
 *
 * **A rating reaches no payslip.** The suggested increment on this screen is a figure for
 * a human to consider and save as a new employee setting themselves — nothing here writes
 * to a package, because wiring a rating to a salary makes the appraisal a payroll
 * instruction and the first disputed rating a payroll incident.
 */
class ReviewResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?int $navigationSort = 11;

    /**
     * Own record and downline — and a review not yet SHARED is hidden from the person it
     * is about even so.
     *
     * Submitted is not shared: a written review is a draft until a manager decides, and
     * showing it earlier would make honest drafting impossible.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (static::userIsPrivileged()) {
            return $query;
        }

        $accessible = static::accessibleEmployeeIds()->all();
        $ownEmployeeId = app(EmployeeAccess::class)->ownEmployeeId(auth()->user());

        return $query->whereIn('employee_id', $accessible)
            ->where(fn (Builder $q) => $q
                ->where('employee_id', '!=', $ownEmployeeId)
                ->orWhereNotNull('shared_at'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The review')
                ->schema([
                    Select::make('review_cycle_id')
                        ->label(__('Cycle'))
                        ->options(fn (): array => ReviewCycle::orderByDesc('period_start')->pluck('name', 'id')->all())
                        ->required(),

                    Select::make('employee_id')
                        ->label(__('Employee'))
                        ->options(fn (): array => app(EmployeeAccess::class)
                            ->scopeAccessibleEmployees(\App\Modules\Employees\Models\Employee::query(), auth()->user())
                            ->get()
                            ->mapWithKeys(fn ($e): array => [$e->id => $e->display_label])
                            ->all())
                        ->searchable()
                        ->required(),

                    // The competencies decide these two when a template is in use — docs/saas-launch-plan.md §14.6.
                    Select::make('review_template_id')
                        ->label(__('Template'))
                        ->options(fn (): array => ReviewTemplate::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Its competencies are rated on the tab below; the two ratings here follow from them.'),

                    // The employee's own when they can give it under My Review — §14.6 — so read-only here.
                    Select::make('self_rating')->label(__('Self rating'))->options(ReviewTemplates::scaleOptions())
                        ->disabled(fn (?Review $record): bool => $record?->review_template_id !== null || ($record?->selfRatingIsTheEmployees() ?? false))
                        ->hidden(fn (?Review $record): bool => $record !== null && ! ($record->cycle?->includes_self_review ?? true))
                        ->helperText(fn (?Review $record): ?string => match (true) {
                            $record?->review_template_id !== null => 'Weighted from the competencies.',
                            $record?->selfRatingIsTheEmployees() ?? false => 'The employee gives this under My Review.',
                            default => null,
                        }),
                    Select::make('manager_rating')->label(__('Manager rating'))->options(ReviewTemplates::scaleOptions())
                        ->disabled(fn (?Review $record): bool => $record?->review_template_id !== null),
                    Select::make('final_rating')->options(ReviewTemplates::scaleOptions())
                        ->helperText('After calibration. An opinion — it reaches no payslip.'),

                    // A recommendation, and nothing more: a promotion is a dated job-history row somebody records.
                    \Filament\Forms\Components\Toggle::make('promotion_recommended')->label(__('Recommend a promotion')),
                    Select::make('promotion_designation')
                        ->label(__('To'))
                        ->options(fn (): array => options('employees.designation'))
                        ->visible(fn (Get $get): bool => (bool) $get('promotion_recommended'))
                        ->helperText('Recorded here only. Record the promotion itself on the employee when it is decided.'),

                    Textarea::make('self_comments')->label("The employee's comments")->rows(3)->columnSpanFull()
                        ->disabled()->dehydrated(false)
                        ->visible(fn (?Review $record): bool => filled($record?->self_comments))
                        ->helperText(fn (?Review $record): ?string => $record?->self_submitted_at
                            ? 'Submitted '.$record->self_submitted_at->format('d M Y H:i').' with their self-review.'
                            : 'Not submitted yet.'),

                    Textarea::make('strengths')->rows(3)->columnSpanFull(),
                    Textarea::make('improvements')->rows(3)->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Merit ledger')
                ->description('Every dated entry inside the cycle\'s period — plan §8.1. Shown beside the rating and never written into it.')
                ->schema([
                    Placeholder::make('merit_evidence')
                        ->label(__('For this period'))
                        ->content(function (?Review $record): string {
                            if (! $record?->cycle) {
                                return 'Save the review to see the ledger for its period.';
                            }

                            $summary = app(\App\Modules\Performance\Services\MeritLedger::class)->summary(
                                $record->employee_id,
                                $record->cycle->period_start->toDateString(),
                                $record->cycle->period_end->toDateString(),
                            );

                            if ($summary['entries']->isEmpty()) {
                                return 'No merit entries in this period.';
                            }

                            return sprintf(
                                '%d entries: +%d credits, %d debits, balance %+d. Open Merit Entries to read each one.',
                                $summary['entries']->count(),
                                $summary['credits'],
                                $summary['debits'],
                                $summary['balance'],
                            );
                        }),
                ]),

            Section::make('Evidence')
                ->description('The monthly progress reports filed inside the cycle\'s period. Read, never copied: an MPR is the employee\'s own account of their month.')
                ->visible(fn (): bool => modules()->enabled('mpr'))
                ->schema([
                    Placeholder::make('mpr_evidence')
                        ->label(__('Monthly reports'))
                        ->content(function (Get $get, ?Review $record): string {
                            if (! $record) {
                                return 'Save the review to see the reports for its period.';
                            }

                            $count = app(ReviewCycleService::class)->evidenceFor($record)->count();

                            return $count === 0
                                ? 'No monthly reports were filed in this period.'
                                : "{$count} monthly report(s) were filed in this period — open MPR to read them.";
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Eager: the increment action's label and state read the request per row.
            ->modifyQueryUsing(fn ($query) => $query->with(['incrementRequest']))
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label(__('Employee'))
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('cycle.name')->label(__('Cycle'))->sortable(),
                TextColumn::make('reviewer.display_label')->label(__('Reviewer'))->placeholder('—')->toggleable(),

                TextColumn::make('promotion_recommended')
                    ->label(__('Promotion'))
                    ->state(fn (Review $r): string => $r->promotion_recommended ? ('recommended'.($r->promotion_designation ? ': '.$r->promotion_designation : '')) : '')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('final_rating')
                    ->label(__('Rating'))
                    ->alignEnd()
                    ->placeholder('—')
                    // Said next to every rating, because this is the screen where somebody
                    // would assume otherwise.
                    ->description(fn (Review $record): ?string => $record->final_rating !== null
                        ? 'no effect on pay'
                        : null),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Review::STATUS_ACKNOWLEDGED => 'success',
                        Review::STATUS_SHARED => 'info',
                        Review::STATUS_PENDING => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('review_cycle_id')
                    ->label(__('Cycle'))
                    ->options(fn (): array => ReviewCycle::orderByDesc('period_start')->pluck('name', 'id')->all()),

                SelectFilter::make('status')->options([
                    Review::STATUS_PENDING => 'Not started',
                    Review::STATUS_SELF_SUBMITTED => 'Self-review in',
                    Review::STATUS_MANAGER_SUBMITTED => 'Manager review in',
                    Review::STATUS_SHARED => 'Shared',
                    Review::STATUS_ACKNOWLEDGED => 'Acknowledged',
                ]),
            ])
            ->recordActions([
                Action::make('submit')
                    ->label(__('Submit'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (Review $record): bool => $record->submitted_at === null
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Review $record): void {
                        $record->update([
                            'status' => Review::STATUS_MANAGER_SUBMITTED,
                            'submitted_at' => now(),
                        ]);

                        Notification::make()->success()
                            ->title('Submitted. It is not visible to the employee until you share it.')
                            ->send();
                    }),

                Action::make('share')
                    ->label(__('Share with them'))
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The employee will be able to read this. Until now it has been a draft about them.')
                    ->visible(fn (Review $record): bool => $record->submitted_at !== null
                        && $record->shared_at === null
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Review $record): void {
                        try {
                            app(ReviewCycleService::class)->share($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Shared.')->send();
                    }),

                /**
                 * The suggestion, made into a proposal — plan §7.10. A change request of the
                 * new-package kind, approved under Employee Change Requests; the review
                 * never writes the package.
                 */
                Action::make('changeReviewer')
                    ->label(__('Change reviewer'))
                    ->icon('heroicon-o-user-minus')
                    ->color('gray')
                    ->visible(fn (Review $record): bool => $record->shared_at === null && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([
                        Select::make('reviewer_employee_id')
                            ->label(__('Reviewer'))
                            ->options(fn (Review $record): array => \App\Modules\Employees\Models\Employee::query()->where('is_active', true)->whereKeyNot($record->employee_id)->with('user')->orderBy('employee_id')->get()
                                ->mapWithKeys(fn ($e): array => [$e->getKey() => $e->display_label])->all())
                            ->searchable()
                            ->nullable(),
                    ])
                    ->fillForm(fn (Review $record): array => ['reviewer_employee_id' => $record->reviewer_employee_id])
                    ->action(function (Review $record, array $data): void {
                        try {
                            app(ReviewCycleService::class)->changeReviewer($record, $data['reviewer_employee_id'] ? (int) $data['reviewer_employee_id'] : null);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Reviewer changed.')->send();
                    }),

                Action::make('increment')
                    ->label(fn (Review $record): string => match ($record->incrementRequest?->status) {
                        null, EmployeeChangeRequest::STATUS_REJECTED => 'Propose increment',
                        EmployeeChangeRequest::STATUS_PENDING => 'Increment proposed',
                        default => 'Increment approved',
                    })
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->visible(fn (Review $record): bool => $record->final_rating !== null
                        && modules()->enabled('payroll')
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->disabled(fn (Review $record): bool => $record->incrementRequest !== null
                        && $record->incrementRequest->status !== EmployeeChangeRequest::STATUS_REJECTED)
                    ->modalHeading('Propose a salary increment')
                    ->modalDescription(function (Review $record): string {
                        $suggestion = app(ReviewCycleService::class)->suggestedIncrement($record);

                        return ($suggestion['current'] !== null
                            ? 'Current basic: '.number_format($suggestion['current'], 2).' — the scale suggests '.number_format($suggestion['suggested'], 2).'. '
                            : '')
                            .'This raises a change request an approver decides; nothing changes until they do.';
                    })
                    ->schema([
                        // A percentage or a figure — docs/saas-launch-plan.md §14.6. Both raise the same request.
                        \Filament\Forms\Components\Radio::make('basis')
                            ->label(__('Give the increase as'))
                            ->options(['percent' => 'A percentage', 'amount' => 'A new basic wage'])
                            ->default('percent')
                            ->inline()
                            ->live(),
                        TextInput::make('percent')
                            ->label(__('Increase (%)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->visible(fn (Get $get): bool => ($get('basis') ?? 'percent') === 'percent')
                            ->required(fn (Get $get): bool => ($get('basis') ?? 'percent') === 'percent')
                            ->default(fn (Review $record): float => app(ReviewCycleService::class)->suggestedPercent($record)),
                        TextInput::make('amount')
                            ->label(__('New basic wage'))
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => $get('basis') === 'amount')
                            ->required(fn (Get $get): bool => $get('basis') === 'amount')
                            ->default(fn (Review $record): ?float => app(ReviewCycleService::class)->suggestedIncrement($record)['suggested']),
                        DatePicker::make('start_date')
                            ->label(__('From'))
                            ->native(false)
                            ->required()
                            ->default(fn (): string => now()->addMonthNoOverflow()->startOfMonth()->toDateString()),
                    ])
                    ->modalSubmitActionLabel('Propose')
                    ->action(function (Review $record, array $data): void {
                        try {
                            ($data['basis'] ?? 'percent') === 'amount'
                                ? app(ReviewCycleService::class)->proposeIncrementOfAmount($record, (float) $data['amount'], (string) $data['start_date'], auth()->user())
                                : app(ReviewCycleService::class)->proposeIncrement($record, (float) $data['percent'], (string) $data['start_date'], auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title('Increment proposed.')
                            ->body('An approver will find it under Employee Change Requests.')
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
            'create' => CreateReview::route('/create'),
            'edit' => EditReview::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        // The competencies — docs/saas-launch-plan.md §14.6.
        return [\App\Modules\Performance\Filament\Resources\Reviews\RelationManagers\RatingsRelationManager::class];
    }
}
