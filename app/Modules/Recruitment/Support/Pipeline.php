<?php

namespace App\Modules\Recruitment\Support;

use App\Modules\Recruitment\Models\Application;

/**
 * Where a candidate has got to, as five steps rather than one word.
 *
 * The stage column holds one value and the screens showed it as a badge, which answers "where are
 * they" and not "how far through are they" — the question anybody looking at a candidate actually
 * has. The same five steps, in the same order, are what a hiring manager pictures: applied,
 * screening, interview, offer, hired.
 *
 * **Rejected and withdrawn are not steps.** They are how the row stopped, and they can happen at any
 * point, so putting them on the line would make a sixth stage that nobody is ever "at". They are
 * shown as the line going cold plus the reason, and `reachedBy()` works out where it stopped from
 * what is on the record.
 */
class Pipeline
{
    /** The five steps, in order. Keys are the stages a candidate is actually *at*. */
    public const STEPS = [
        Application::STAGE_APPLIED => 'Applied',
        Application::STAGE_SCREENING => 'Screening',
        Application::STAGE_INTERVIEW => 'Interview',
        Application::STAGE_OFFER => 'Offer',
        Application::STAGE_HIRED => 'Hired',
    ];

    public const DONE = 'done';

    public const CURRENT = 'current';

    public const TODO = 'todo';

    /**
     * The five steps with the state each is in for this candidate.
     *
     * @return array<int, array{key: string, label: string, number: int, state: string}>
     */
    public static function for(Application $application): array
    {
        $keys = array_keys(self::STEPS);
        $reached = (int) array_search(self::reachedBy($application), $keys, true);

        // Nothing is "current" once the row has stopped moving: a hired candidate is finished and a
        // rejected one is not waiting at a step, they are off the line. Both leave what they reached
        // marked done, which is the honest reading of a pipeline nobody is going to advance.
        $settled = self::hasEnded($application) || $application->stage === Application::STAGE_HIRED;

        $steps = [];

        foreach ($keys as $i => $key) {
            $steps[] = [
                'key' => $key,
                'label' => self::STEPS[$key],
                'number' => $i + 1,
                'state' => match (true) {
                    $i < $reached => self::DONE,
                    $i === $reached => $settled ? self::DONE : self::CURRENT,
                    default => self::TODO,
                },
            ];
        }

        return $steps;
    }

    /** Whether this application stopped rather than finished. */
    public static function hasEnded(Application $application): bool
    {
        return in_array($application->stage, [Application::STAGE_REJECTED, Application::STAGE_WITHDRAWN], true);
    }

    /**
     * The furthest step this candidate actually reached.
     *
     * For an open application that is simply the stage. A rejected one keeps `rejected` in the column
     * and loses where it happened, so the evidence answers instead: an offer on the record means they
     * reached Offer, an interview means Interview. Without that, every rejected candidate's line read
     * as though they never got past Applied — a different and unkinder story than the one the record
     * actually tells, and the one somebody reviewing a rejection would be misled by.
     *
     * Asked as `exists()` rather than through the relations because this runs while the page is being
     * drawn, and lazy loading is prevented outside production.
     */
    public static function reachedBy(Application $application): string
    {
        if (! self::hasEnded($application)) {
            return array_key_exists($application->stage, self::STEPS)
                ? $application->stage
                : Application::STAGE_APPLIED;
        }

        return match (true) {
            $application->offer()->exists() => Application::STAGE_OFFER,
            $application->interviews()->exists() => Application::STAGE_INTERVIEW,
            default => Application::STAGE_APPLIED,
        };
    }
}
