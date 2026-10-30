<?php

namespace App\Modules\Performance\Filament\Resources\Reviews\RelationManagers;

use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewRating;
use App\Modules\Performance\Services\ReviewTemplates;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The competencies, rated by the two of them — docs/saas-launch-plan.md §14.6. The employee
 * fills their own column, the reviewer theirs; the weighted figures land on the review.
 */
class RatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'ratings';

    protected static ?string $title = 'Competencies';

    public function form(Schema $schema): Schema
    {
        // The employee's column is theirs when they can fill it in themselves (My Review): shown beside
        // the reviewer's, never written over. Somebody with no login cannot, so whoever writes the review
        // types in the self-assessment they handed over. A cycle with no self-review has no such column.
        $selfHidden = fn (): bool => ! ($this->review()->cycle?->includes_self_review ?? true);
        $selfLocked = fn (): bool => $this->review()->selfRatingIsTheEmployees();

        return $schema->components([
            Select::make('self_rating')->label('Employee')->options(ReviewTemplates::scaleOptions())->nullable()
                ->hidden($selfHidden)->disabled($selfLocked)->dehydrated(fn (): bool => ! $selfLocked()),
            Textarea::make('self_note')->label("Employee's note")->rows(2)
                ->hidden($selfHidden)->disabled($selfLocked)->dehydrated(fn (): bool => ! $selfLocked()),
            Select::make('manager_rating')->label('Reviewer')->options(ReviewTemplates::scaleOptions())->nullable(),
            Textarea::make('manager_note')->label("Reviewer's note")->rows(2),
        ]);
    }

    private function review(): Review
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Review ? $owner->loadMissing('cycle', 'employee') : $owner;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('competency')->join('review_competencies', 'review_competencies.id', '=', 'review_ratings.review_competency_id')->orderBy('review_competencies.sort')->select('review_ratings.*'))
            ->columns([
                TextColumn::make('competency.name')->label('Competency')->description(fn (ReviewRating $r): ?string => $r->competency?->description),
                TextColumn::make('competency.weight')->label('Weight')->suffix('%')->alignEnd(),
                TextColumn::make('self_rating')->label('Employee')->placeholder('—')->description(fn (ReviewRating $r): ?string => $r->self_note)
                    ->visible(fn (): bool => $this->review()->cycle?->includes_self_review ?? true),
                TextColumn::make('manager_rating')->label('Reviewer')->placeholder('—')->description(fn (ReviewRating $r): ?string => $r->manager_note),
            ])
            ->recordActions([
                Action::make('rate')
                    ->label('Rate')
                    ->icon('heroicon-o-star')
                    ->schema(fn (): array => $this->form(\Filament\Schemas\Schema::make())->getComponents())
                    ->fillForm(fn (ReviewRating $record): array => $record->only(['self_rating', 'self_note', 'manager_rating', 'manager_note']))
                    ->action(function (ReviewRating $record, array $data): void {
                        $record->update($data);
                        app(ReviewTemplates::class)->syncRatings($record->review);
                        Notification::make()->success()->title('Rated. The weighted figures on the review follow.')->send();
                    }),
            ])
            ->headerActions([
                Action::make('recalculate')
                    ->label('Recalculate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (): void {
                        $review = $this->getOwnerRecord();
                        app(ReviewTemplates::class)->syncRatings($review instanceof Review ? $review : $review->refresh());
                        Notification::make()->success()->title('The weighted ratings are up to date.')->send();
                    }),
            ]);
    }
}
