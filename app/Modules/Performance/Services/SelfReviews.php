<?php

namespace App\Modules\Performance\Services;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewRating;
use App\Notifications\RecordChanged;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * The employee's half of a review — docs/saas-launch-plan.md §14.6.
 *
 * Every write is checked against two things, here rather than on the screen: that the review is the
 * signed-in person's own, and that it is still waiting for them. The screen offers nothing else, but the
 * screen is not the boundary.
 */
class SelfReviews
{
    public function __construct(
        private readonly ReviewTemplates $templates,
        private readonly EmployeeAccess $access,
    ) {}

    /** @return Collection<int, Review> the signed-in person's reviews waiting for their half, oldest first */
    public function awaiting(?User $user): Collection
    {
        $employeeId = $this->access->ownEmployeeId($user);

        if ($employeeId === null) {
            return new Collection;
        }

        return Review::query()
            ->with(['cycle', 'template', 'reviewer.user'])
            ->where('employee_id', $employeeId)
            ->awaitingSelfReview()
            ->orderBy('id')
            ->get();
    }

    public function rate(ReviewRating $rating, User $by, ?int $score, ?string $note): ReviewRating
    {
        $review = $this->ownOpenReview($rating->review, $by);

        if ($score !== null && ! array_key_exists($score, ReviewTemplates::scaleOptions())) {
            throw new InvalidArgumentException("{$score} is not on the rating scale.");
        }

        $rating->update(['self_rating' => $score, 'self_note' => blank($note) ? null : trim((string) $note)]);
        $this->templates->syncRatings($review);

        return $rating->refresh();
    }

    /** The comments beside the ratings, and the overall rating where the review has no competencies. */
    public function comment(Review $review, User $by, ?string $comments, ?int $overall = null): Review
    {
        $review = $this->ownOpenReview($review, $by);
        $changes = ['self_comments' => blank($comments) ? null : trim((string) $comments)];

        if (! $review->ratings()->exists()) {
            if ($overall !== null && ! array_key_exists($overall, ReviewTemplates::scaleOptions())) {
                throw new InvalidArgumentException("{$overall} is not on the rating scale.");
            }

            $changes['self_rating'] = $overall;
        }

        $review->forceFill($changes)->save();

        return $review->refresh();
    }

    /** Hand the half over. Every competency rated, or the overall rating where there are none. */
    public function submit(Review $review, User $by): Review
    {
        $review = $this->ownOpenReview($review, $by);

        $unrated = $review->ratings()->whereNull('self_rating')->count();

        if ($unrated > 0) {
            throw new InvalidArgumentException("Rate every competency first — {$unrated} still without a rating.");
        }

        if (! $review->ratings()->exists() && $review->self_rating === null) {
            throw new InvalidArgumentException('Give yourself an overall rating first.');
        }

        $review->forceFill([
            'self_submitted_at' => now(),
            'status' => $review->status === Review::STATUS_PENDING ? Review::STATUS_SELF_SUBMITTED : $review->status,
        ])->save();

        // The reviewer is who waits on this; the bell is enough — they are opening the review anyway.
        $review->reviewer?->user?->notify(new RecordChanged(
            'A self-review is in',
            ($review->employee?->fullName() ?? 'An employee').' has submitted their self-review for '
                .($review->cycle?->name ?? 'the cycle').'. Their ratings sit beside yours on the review.',
        ));

        return $review->refresh();
    }

    private function ownOpenReview(Review $review, User $by): Review
    {
        $review->loadMissing('cycle', 'employee');

        if ($review->employee_id === null || $review->employee_id !== $this->access->ownEmployeeId($by)) {
            throw new InvalidArgumentException('That review is not yours.');
        }

        if (! $review->awaitsSelfReview()) {
            throw new InvalidArgumentException('This review is not waiting for your self-review.');
        }

        return $review;
    }
}
