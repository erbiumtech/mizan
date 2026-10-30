<?php

namespace App\Modules\Recruitment\Services;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\InterviewFeedback;
use App\Notifications\RecordChanged;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Scheduling, the panel's feedback, and the outcome that moves the stage —
 * docs/saas-launch-plan.md §14.6.
 */
class Interviews
{
    /** @param  array<int, int>  $interviewerUserIds */
    public function schedule(Application $application, string|Carbon $at, array $interviewerUserIds, string $mode = 'in_person', ?string $location = null, ?int $round = null): Interview
    {
        $interviewerUserIds = array_values(array_unique(array_filter(array_map('intval', $interviewerUserIds))));

        if ($interviewerUserIds === []) {
            throw new InvalidArgumentException('An interview needs at least one interviewer.');
        }

        if (in_array($application->stage, [Application::STAGE_HIRED, Application::STAGE_REJECTED, Application::STAGE_WITHDRAWN], true)) {
            throw new InvalidArgumentException("This application is {$application->stage}; there is nothing to interview for.");
        }

        $interview = Interview::create([
            'application_id' => $application->getKey(),
            'round' => $round ?? ((int) Interview::query()->where('application_id', $application->getKey())->max('round') + 1),
            'scheduled_at' => Carbon::parse($at),
            'mode' => $mode,
            'location' => $location,
            'interviewer_user_ids' => $interviewerUserIds,
            'outcome' => Interview::OUTCOME_PENDING,
        ]);

        // A feedback row each, waiting to be filled: the form exists before anybody opens it,
        // so "who has not answered" is a question the list can answer.
        foreach ($interviewerUserIds as $userId) {
            InterviewFeedback::create(['interview_id' => $interview->getKey(), 'user_id' => $userId]);
        }

        // Interviewing is a stage of its own, and scheduling one is what reaches it.
        if (in_array($application->stage, [Application::STAGE_APPLIED, Application::STAGE_SCREENING], true)) {
            $application->update(['stage' => Application::STAGE_INTERVIEW]);
        }

        foreach (User::query()->whereKey($interviewerUserIds)->where('status', 1)->get() as $user) {
            $user->notify(new RecordChanged(
                'You are interviewing '.($application->applicant?->name ?? 'a candidate'),
                $interview->scheduled_at->toDayDateTimeString().($location ? ', '.$location : '').'. Your feedback form is on the interview.',
            ));
        }

        return $interview->refresh();
    }

    public function recordFeedback(Interview $interview, User $by, array $data): InterviewFeedback
    {
        $panel = (array) ($interview->interviewer_user_ids ?? []);

        if ($panel !== [] && ! in_array((int) $by->getKey(), array_map('intval', $panel), true)) {
            throw new InvalidArgumentException('Only somebody on the panel gives feedback on this interview.');
        }

        $feedback = InterviewFeedback::query()->firstOrNew(['interview_id' => $interview->getKey(), 'user_id' => $by->getKey()]);
        $feedback->fill([
            'rating' => $data['rating'] ?? null,
            'recommendation' => $data['recommendation'] ?? null,
            'strengths' => $data['strengths'] ?? null,
            'concerns' => $data['concerns'] ?? null,
            'submitted_at' => now(),
        ])->save();

        return $feedback->refresh();
    }

    /**
     * The outcome, and what it does to the application — docs/saas-launch-plan.md §14.6.
     * Passed leaves the stage where it is, because the next round or the offer is a
     * decision somebody makes; failed and no-show move it to rejected.
     */
    public function decide(Interview $interview, string $outcome, ?string $notes = null): Interview
    {
        if (! in_array($outcome, [Interview::OUTCOME_PASSED, Interview::OUTCOME_FAILED, Interview::OUTCOME_NO_SHOW], true)) {
            throw new InvalidArgumentException("\"{$outcome}\" is not an interview outcome.");
        }

        $interview->update(['outcome' => $outcome, 'notes' => $notes ?? $interview->notes]);
        $application = $interview->application;

        if ($application !== null && in_array($outcome, [Interview::OUTCOME_FAILED, Interview::OUTCOME_NO_SHOW], true) && in_array($application->stage, Application::OPEN_STAGES, true)) {
            $application->update([
                'stage' => Application::STAGE_REJECTED,
                'rejected_reason' => $outcome === Interview::OUTCOME_NO_SHOW ? 'Did not attend the interview' : 'Interview round '.$interview->round,
                'rejected_at' => now(),
            ]);
        }

        return $interview->refresh();
    }

    /**
     * Every round on an application, oldest first, with what a reader needs to see.
     *
     * **Built here rather than in the view** because three screens ask the same question — the
     * candidate page, the interviews list and the panel's own feedback form — and a round that
     * reads "Round 2 · Passed · 2 of 3" on one of them and something else on another is the kind
     * of difference nobody notices until they are comparing two tabs.
     *
     * The panel's names are resolved in one lookup for every round at once. Asking per round was
     * an N+1 across a table that lives in the landlord database, which is the expensive kind.
     *
     * @return array<int, array{id: int, round: int, when: ?\Illuminate\Support\Carbon, mode: string,
     *     location: ?string, panel: array<int, string>, outcome: string, outcomeLabel: string,
     *     decided: bool, notes: ?string, feedback: array{given: int, of: int, average: ?float}}>
     */
    public function rounds(Application $application): array
    {
        $interviews = Interview::query()
            ->where('application_id', $application->getKey())
            ->with('feedback')
            ->orderBy('round')
            ->get();

        $names = User::query()
            ->whereIn('id', $interviews
                ->flatMap(fn (Interview $i): array => (array) ($i->interviewer_user_ids ?? []))
                ->unique()
                ->all())
            ->pluck('name', 'id');

        return $interviews->map(fn (Interview $i): array => [
            'id' => (int) $i->getKey(),
            'round' => (int) $i->round,
            'when' => $i->scheduled_at,
            'mode' => Interview::MODES[$i->mode] ?? $i->mode,
            'location' => $i->location,
            // A panel member whose account has since gone is named as gone rather than dropped:
            // "with two people" when the record says three is a quieter lie than an awkward word.
            'panel' => collect((array) ($i->interviewer_user_ids ?? []))
                ->map(fn ($id): string => $names[$id] ?? 'A former colleague')
                ->all(),
            'outcome' => $i->outcome,
            'outcomeLabel' => Interview::OUTCOMES[$i->outcome] ?? $i->outcome,
            'decided' => $i->isDecided(),
            'notes' => $i->notes,
            'feedback' => $this->feedbackSummary($i),
        ])->all();
    }

    /** @return array{given: int, of: int, average: ?float} */
    public function feedbackSummary(Interview $interview): array
    {
        // The loaded relation when there is one. The interviews list eager-loads `feedback` and this
        // was querying per row anyway — twice per row, in fact, because the column asked once for the
        // text and once for the colour.
        $rows = $interview->relationLoaded('feedback')
            ? $interview->feedback
            : InterviewFeedback::query()->where('interview_id', $interview->getKey())->get();
        $given = $rows->whereNotNull('submitted_at');

        return [
            'given' => $given->count(),
            'of' => max($rows->count(), count((array) ($interview->interviewer_user_ids ?? []))),
            'average' => $given->whereNotNull('rating')->isEmpty() ? null : round((float) $given->whereNotNull('rating')->avg('rating'), 1),
        ];
    }
}
