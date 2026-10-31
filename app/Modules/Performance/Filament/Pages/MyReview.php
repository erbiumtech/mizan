<?php

namespace App\Modules\Performance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewRating;
use App\Modules\Performance\Services\ReviewTemplates;
use App\Modules\Performance\Services\SelfReviews;
use App\Support\EmployeeAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The employee's own half of a review — docs/saas-launch-plan.md §14.6.
 *
 * A page of its own rather than a door into the Reviews resource: that resource is the manager's, and a
 * review is hidden from the person it is about until it is shared. This shows only the signed-in person's
 * review while their self-review is open, and SelfReviews refuses anything else whatever is posted.
 */
class MyReview extends Page implements HasTable
{
    use BelongsToModule;
    use InteractsWithTable;

    protected string $view = 'filament.pages.simple-table';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    // No group, so it is in Home with the other screens about the person looking at them — see the manifest.
    protected static ?string $title = 'My Review';

    protected static ?int $navigationSort = 65;

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && app(EmployeeAccess::class)->isEmployee(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(SelfReviews::class)->awaiting(auth()->user())->count();

        return $count > 0 ? (string) $count : null;
    }

    /** The oldest review waiting for this person — one at a time, as a person answers them. */
    public function review(): ?Review
    {
        return app(SelfReviews::class)->awaiting(auth()->user())->first();
    }

    public function getSubheading(): ?string
    {
        $review = $this->review();

        if ($review === null) {
            return 'Nothing is waiting for your self-review. When a review cycle asks for one, it appears here.';
        }

        return $review->cycle->name
            .($review->cycle->self_review_due_on ? ' — due '.$review->cycle->self_review_due_on->format('d M Y') : '')
            .($review->reviewer ? '. Your reviewer, '.$review->reviewer->fullName().', sees your ratings beside theirs.' : '.')
            .' Rate each competency, add your comments, then submit.';
    }

    public function table(Table $table): Table
    {
        $review = $this->review();

        return $table
            ->query(fn (): Builder => ReviewRating::query()
                ->with('competency')
                ->where('review_id', $review?->getKey() ?? 0)
                ->join('review_competencies', 'review_competencies.id', '=', 'review_ratings.review_competency_id')
                ->orderBy('review_competencies.sort')
                ->select('review_ratings.*'))
            ->paginated(false)
            ->emptyStateHeading($review === null ? 'Nothing to review' : 'No competencies on this review')
            ->emptyStateDescription($review === null ? null : 'Give yourself an overall rating under Comments.')
            ->columns([
                TextColumn::make('competency.name')->label(__('Competency'))->description(fn (ReviewRating $r): ?string => $r->competency?->description),
                TextColumn::make('competency.weight')->label(__('Weight'))->suffix('%')->alignEnd(),
                TextColumn::make('self_rating')->label(__('Your rating'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '' : (ReviewTemplates::scaleOptions()[$state] ?? (string) $state))
                    ->placeholder('not yet')
                    ->description(fn (ReviewRating $r): ?string => $r->self_note),
            ])
            ->recordActions([
                Action::make('rate')
                    ->label(__('Rate'))
                    ->icon('heroicon-o-star')
                    ->fillForm(fn (ReviewRating $record): array => ['self_rating' => $record->self_rating, 'self_note' => $record->self_note])
                    ->schema([
                        Select::make('self_rating')->label(__('Your rating'))->options(ReviewTemplates::scaleOptions())->required(),
                        Textarea::make('self_note')->label(__('Why'))->rows(3)->maxLength(250)
                            ->helperText('What you did that shows it. Your reviewer reads this beside their own note.'),
                    ])
                    ->action(function (ReviewRating $record, array $data): void {
                        try {
                            app(SelfReviews::class)->rate($record, auth()->user(), (int) $data['self_rating'], $data['self_note'] ?? null);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Saved.')->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('my-review', 'My Review: Help'),

            Action::make('comments')
                ->label(__('Comments'))
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->visible(fn (): bool => $this->review() !== null)
                ->fillForm(fn (): array => ['self_comments' => $this->review()?->self_comments, 'self_rating' => $this->review()?->self_rating])
                ->schema([
                    Select::make('self_rating')->label(__('Overall rating'))->options(ReviewTemplates::scaleOptions())
                        ->visible(fn (): bool => ! ($this->review()?->ratings()->exists() ?? false))
                        ->required(fn (): bool => ! ($this->review()?->ratings()->exists() ?? false))
                        ->helperText('This review has no competencies, so you rate the period as a whole.'),
                    Textarea::make('self_comments')->label(__('Your comments'))->rows(6)->maxLength(4000)
                        ->helperText('The period in your words: what went well, what got in the way, what you want next.'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(SelfReviews::class)->comment($this->review(), auth()->user(), $data['self_comments'] ?? null, isset($data['self_rating']) ? (int) $data['self_rating'] : null);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Saved.')->send();
                }),

            Action::make('submit')
                ->label(__('Submit my self-review'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->review() !== null)
                ->requiresConfirmation()
                ->modalDescription('Your reviewer is told, and your ratings can no longer be changed.')
                ->action(function (): void {
                    try {
                        app(SelfReviews::class)->submit($this->review(), auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Submitted. Your reviewer has been told.')->send();
                    $this->resetTable();
                }),
        ];
    }
}
