<?php

namespace App\Modules\Performance\Filament\Resources\Reviews;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\CreateReview;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\EditReview;
use App\Modules\Performance\Filament\Resources\Reviews\Pages\ListReviews;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Services\ReviewCycleService;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Actions\Action;
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
        $ownEmployeeId = \App\Modules\Employees\Models\Employee::where('user_id', auth()->id())->value('id');

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
                        ->label('Cycle')
                        ->options(fn (): array => ReviewCycle::orderByDesc('period_start')->pluck('name', 'id')->all())
                        ->required(),

                    Select::make('employee_id')
                        ->label('Employee')
                        ->options(fn (): array => app(EmployeeAccess::class)
                            ->scopeAccessibleEmployees(\App\Modules\Employees\Models\Employee::query(), auth()->user())
                            ->get()
                            ->mapWithKeys(fn ($e): array => [$e->id => $e->display_label])
                            ->all())
                        ->searchable()
                        ->required(),

                    TextInput::make('self_rating')->numeric()->minValue(1)->maxValue(5),
                    TextInput::make('manager_rating')->numeric()->minValue(1)->maxValue(5),
                    TextInput::make('final_rating')->numeric()->minValue(1)->maxValue(5)
                        ->helperText('After calibration. An opinion — it reaches no payslip.'),

                    Textarea::make('strengths')->rows(3)->columnSpanFull(),
                    Textarea::make('improvements')->rows(3)->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Evidence')
                ->description('The monthly progress reports filed inside the cycle\'s period. Read, never copied: an MPR is the employee\'s own account of their month.')
                ->visible(fn (): bool => modules()->enabled('mpr'))
                ->schema([
                    Placeholder::make('mpr_evidence')
                        ->label('Monthly reports')
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
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('cycle.name')->label('Cycle')->sortable(),
                TextColumn::make('reviewer.display_label')->label('Reviewer')->placeholder('—')->toggleable(),

                TextColumn::make('final_rating')
                    ->label('Rating')
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
                    ->label('Cycle')
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
                    ->label('Submit')
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
                    ->label('Share with them')
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

                Action::make('increment')
                    ->label('Suggested increment')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->visible(fn (Review $record): bool => $record->final_rating !== null
                        && modules()->enabled('payroll'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalDescription(function (Review $record): string {
                        $suggestion = app(ReviewCycleService::class)->suggestedIncrement($record);

                        if ($suggestion['suggested'] === null) {
                            return $suggestion['note'];
                        }

                        return 'Current basic: '.number_format($suggestion['current'], 2)
                            .' — suggested: '.number_format($suggestion['suggested'], 2)
                            ."\n\n".$suggestion['note'];
                    })
                    ->action(fn () => null),

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
}
