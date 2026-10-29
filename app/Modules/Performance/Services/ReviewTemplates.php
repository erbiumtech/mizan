<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewRating;
use App\Modules\Performance\Models\ReviewTemplate;
use InvalidArgumentException;

/**
 * Competencies on a review, and the weighted figure they make —
 * docs/saas-launch-plan.md §14.6.
 *
 * The weighted rating is rounded to the scale the company uses, because every
 * reader of `self_rating` and `manager_rating` — the list, the report, the
 * increment scale — was written for a whole number on that scale.
 */
class ReviewTemplates
{
    /** The highest a rating goes. A company setting, because a five-point scale is a convention. */
    public static function scaleMax(): int
    {
        return max(2, (int) setting('performance.rating_scale', config('performance.rating_scale', 5)));
    }

    /** @return array<int, string> */
    public static function scaleOptions(): array
    {
        $labels = (array) config('performance.rating_labels', []);

        return collect(range(1, static::scaleMax()))->mapWithKeys(fn (int $n): array => [$n => $labels[$n] ?? (string) $n])->all();
    }

    /** Put a template's competencies on a review, keeping anything already rated. */
    public function apply(Review $review, ReviewTemplate $template): int
    {
        if ($template->weightProblem() !== null) {
            throw new InvalidArgumentException($template->name.': '.$template->weightProblem());
        }

        $made = 0;

        foreach ($template->competencies as $competency) {
            $rating = ReviewRating::query()->firstOrNew(['review_id' => $review->getKey(), 'review_competency_id' => $competency->getKey()]);

            if (! $rating->exists) {
                $rating->save();
                $made++;
            }
        }

        $review->update(['review_template_id' => $template->getKey()]);

        return $made;
    }

    /**
     * The weighted average of one side's ratings, on the company's scale — or null
     * when nothing has been rated. Weights of the unrated competencies are left out,
     * so a half-finished review reads as the average of what was answered rather
     * than as a low score.
     */
    public function weighted(Review $review, string $side): ?float
    {
        $column = $side === 'self' ? 'self_rating' : 'manager_rating';
        $rows = ReviewRating::query()->where('review_id', $review->getKey())->whereNotNull($column)->with('competency')->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $weight = (float) $rows->sum(fn (ReviewRating $r): float => (float) ($r->competency?->weight ?? 0));

        if ($weight <= 0) {
            return round((float) $rows->avg($column), 2);
        }

        return round($rows->sum(fn (ReviewRating $r): float => (float) $r->{$column} * (float) ($r->competency?->weight ?? 0)) / $weight, 2);
    }

    /** Write both weighted figures onto the review as whole numbers, which is what every reader expects. */
    public function syncRatings(Review $review): Review
    {
        $self = $this->weighted($review, 'self');
        $manager = $this->weighted($review, 'manager');

        $review->forceFill(array_filter([
            'self_rating' => $self === null ? null : (int) round($self),
            'manager_rating' => $manager === null ? null : (int) round($manager),
        ], fn ($value): bool => $value !== null))->save();

        return $review->refresh();
    }
}
