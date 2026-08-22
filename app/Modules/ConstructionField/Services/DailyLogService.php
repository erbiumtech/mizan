<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogDelivery;
use App\Modules\ConstructionField\Models\DailyLogEvent;
use App\Modules\ConstructionField\Models\DailyLogPhoto;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The site diary — `docs/construction-management-plan.md` §16.1.
 *
 * Three rules live here rather than in a form, and all three are about the diary being *evidence*:
 *
 *  - **One diary per job per day.** The unique index enforces it; this service refuses it with a sentence, because a
 *    database constraint violation on a site foreman's screen is not an explanation. §16.1: "the constraint is the
 *    feature, because two site diaries for one day is how a dispute starts."
 *  - **Approval locks the row, and its children with it.** "An editable site diary is not evidence." Nothing about an
 *    approved day may change — including its manpower, plant and events, which is where the numbers are.
 *  - **Reopening is a deliberate act with an author and a reason.** Refusing entirely would leave a wrong signed diary
 *    wrong for ever, and a company in that position keeps its real diary in a notebook — which is worse than a
 *    recorded correction by a wide margin.
 */
class DailyLogService
{
    /**
     * Open the day's diary, or refuse because it already exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(Job $job, string $date, array $attributes = []): DailyLog
    {
        $date = Carbon::parse($date)->toDateString();

        if (DailyLog::query()->where('job_id', $job->getKey())->on($date)->exists()) {
            throw new InvalidArgumentException(
                "{$job->code} already has a diary for {$date}. Two site diaries for one day is how a dispute starts — "
                .'open the existing one and add to it.'
            );
        }

        return TenantTransaction::run(fn (): DailyLog => DailyLog::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'log_date' => $date,
        ])));
    }

    /**
     * Update the day, refused once it is approved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(DailyLog $log, array $attributes): DailyLog
    {
        $this->requireOpen($log, 'changed');

        $log->update($attributes);

        return $log->refresh();
    }

    /** Submitted by whoever wrote it, which is a different person from whoever signs it off. */
    public function submit(DailyLog $log): DailyLog
    {
        $this->requireOpen($log, 'submitted');

        $log->update([
            'submitted_by' => $log->submitted_by ?? auth()->id(),
            'submitted_at' => $log->submitted_at ?? now(),
        ]);

        return $log->refresh();
    }

    /**
     * **Approve, which locks the day.**
     *
     * §16.1's rule in one method. The name and the time are recorded rather than implied by a status, because "who
     * signed this day off" is the first question asked when a diary is produced in a dispute.
     */
    public function approve(DailyLog $log): DailyLog
    {
        if ($log->isApproved()) {
            throw new InvalidArgumentException(
                "{$log->displayName()} was approved on {$log->approved_at->toDateString()}. It is locked, which is "
                .'what makes it evidence.'
            );
        }

        return TenantTransaction::run(function () use ($log): DailyLog {
            $log->update([
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                // Submission is implied by approval where nobody submitted it separately — a one-person site office is
                // the ordinary case, and refusing to approve an unsubmitted day would make the diary unfillable there.
                'submitted_by' => $log->submitted_by ?? auth()->id(),
                'submitted_at' => $log->submitted_at ?? now(),
            ]);

            return $log->refresh();
        });
    }

    /**
     * Reopen a signed day, with a reason.
     *
     * The reason is mandatory and kept: a diary that has been reopened is a diary somebody will ask about, and "the
     * plant hours were transposed" is an answer where a silently amended day is not. The approval stamps are cleared so
     * the day has to be signed off again by somebody, rather than carrying an approval that predates the change.
     */
    public function reopen(DailyLog $log, string $reason): DailyLog
    {
        if (! $log->isApproved()) {
            throw new InvalidArgumentException("{$log->displayName()} is not approved, so it is already open.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Reopening a signed diary needs a reason. Somebody put their name to that day, and the correction has '
                .'to say what was wrong with it.'
            );
        }

        return TenantTransaction::run(function () use ($log, $reason): DailyLog {
            $log->update([
                'approved_by' => null,
                'approved_at' => null,
                'reopened_at' => now(),
                'reopened_by' => auth()->id(),
                'reopen_reason' => $reason,
            ]);

            return $log->refresh();
        });
    }

    /**
     * Add a manpower, plant or event row.
     *
     * One method for all three because the only rule that matters is the same for each: an approved day takes no new
     * rows. §16.1's lock is about the *numbers*, and the numbers are in the children.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addManpower(DailyLog $log, array $attributes): \App\Modules\ConstructionField\Models\DailyLogManpower
    {
        $this->requireOpen($log, 'added to');

        if ((int) ($attributes['headcount'] ?? 0) <= 0 && (float) ($attributes['hours'] ?? 0) <= 0.0) {
            throw new InvalidArgumentException(
                'A manpower line needs a headcount or hours. A row of nothing adds a company to the day without '
                .'adding anybody to the site.'
            );
        }

        return $log->manpower()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function addPlant(DailyLog $log, array $attributes): \App\Modules\ConstructionField\Models\DailyLogPlant
    {
        $this->requireOpen($log, 'added to');

        $total = (float) ($attributes['working_hours'] ?? 0)
            + (float) ($attributes['idle_hours'] ?? 0)
            + (float) ($attributes['breakdown_hours'] ?? 0);

        if ($total <= 0.0) {
            throw new InvalidArgumentException(
                'A plant line needs hours against it. A machine that was not on site is a line nobody enters.'
            );
        }

        return $log->plant()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function addEvent(DailyLog $log, array $attributes): DailyLogEvent
    {
        $this->requireOpen($log, 'added to');

        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An event needs describing. "Stoppage" with no detail is a line nobody can assess in six months, and '
                .'the diary is the only document written while anybody still remembers.'
            );
        }

        if (! array_key_exists($attributes['kind'] ?? '', DailyLogEvent::KINDS)) {
            throw new InvalidArgumentException('An event needs a kind.');
        }

        return $log->events()->create($attributes);
    }

    /**
     * Record a delivery — **the docket, never the valuation**.
     *
     * §16.1's delivery child carries no rate and no amount, and that absence is what keeps it out of the way of §5's
     * goods receipt. A site record of what arrived and who signed for it is a different document from the priced
     * receipt accounts posts, and a contractor whose commercial side is elsewhere still needs the first one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addDelivery(DailyLog $log, array $attributes): DailyLogDelivery
    {
        $this->requireOpen($log, 'added to');

        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A delivery needs to say what arrived. A docket number on its own is a reference to a piece of paper '
                .'nobody here can read.'
            );
        }

        return $log->deliveries()->create($attributes);
    }

    /**
     * Attach a photograph to the day.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addPhoto(DailyLog $log, array $attributes): DailyLogPhoto
    {
        $this->requireOpen($log, 'added to');

        if (blank($attributes['file_path'] ?? null)) {
            throw new InvalidArgumentException('A photograph needs a file.');
        }

        if (trim((string) ($attributes['caption'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A photograph needs a caption. An uncaptioned image is unfindable by month four, which is the first '
                .'month anybody looks.'
            );
        }

        return $log->photos()->create($attributes + ['taken_at' => $log->log_date]);
    }

    /**
     * **Deliveries site signed for that the cost ledger has never seen** — across a job.
     *
     * The procurement counterpart of `unnotifiedEvents()`, and the same kind of silence: material received, cost not
     * recorded, margin overstated, and no error anywhere to find. §5's three-way match catches the invoice that does
     * not match an order; nothing catches the docket that never left the site hut.
     *
     * **Empty without `construction_costing`, deliberately.** With no cost ledger there are no goods receipts, so every
     * delivery would be listed and the report would be the register itself — a control that fires on everything is a
     * control people learn to click through, which is the same argument `config/construction.php` makes about
     * tolerances.
     *
     * @return Collection<int, DailyLogDelivery>
     */
    public function unreceiptedDeliveries(Job $job): Collection
    {
        if (! modules()->enabled('construction_costing')) {
            return collect();
        }

        return DailyLogDelivery::query()
            ->with('dailyLog')
            ->unreceipted()
            // A rejected load was sent back, so nobody should be receipting it and its absence is not an exposure.
            ->where('condition', '!=', DailyLogDelivery::CONDITION_REJECTED)
            ->whereIn('daily_log_id', DailyLog::query()->forJobTree($job)->select('id'))
            ->get();
    }

    /**
     * What the diary says is standing on site — **corroboration, not the figure**.
     *
     * Phase 8c computes materials on site from the unconsumed part of the stock lots, and that is what a certificate
     * quotes, because it goes *down* when material is built in. A count of flagged dockets can only ever go up, so it
     * would overstate the position by everything already consumed and the error would grow monthly.
     *
     * What it is for: where the cost module is absent there is no stock ledger at all, and these dockets are the only
     * record of what is on site. A screen that showed nothing in that case would be §18.1's healthy figure hiding an
     * absence — so it shows this, and says which source it used.
     *
     * @return Collection<int, DailyLogDelivery>
     */
    public function materialsOnSiteDeliveries(Job $job): Collection
    {
        return DailyLogDelivery::query()
            ->with('dailyLog')
            ->onSite()
            ->where('condition', '!=', DailyLogDelivery::CONDITION_REJECTED)
            ->whereIn('daily_log_id', DailyLog::query()->forJobTree($job)->select('id'))
            ->get();
    }

    /**
     * Photographs of covered work that never reached the register — across a job.
     *
     * §16.1 keeps photos out of the ISO 19650 register on purpose, and then names the exception: the handful that are
     * as-built evidence. This is the list of ones that qualify and have not been promoted.
     *
     * @return Collection<int, DailyLogPhoto>
     */
    public function photosNeedingPromotion(Job $job): Collection
    {
        return DailyLogPhoto::query()
            ->with('dailyLog')
            ->evidential()
            ->whereNull('promoted_document_id')
            ->whereIn('daily_log_id', DailyLog::query()->forJobTree($job)->select('id'))
            ->get();
    }

    /**
     * **Diary events that cost time and that nobody has notified** — across a job, not one day.
     *
     * §13 says a claim lost to a missed notice "fails in absolute silence"; §16.1's diary is where the evidence of such
     * an event is written down on the day. Joining the two is the point of this method: a list of hours already lost,
     * already recorded, and already outside somebody's contractual window unless a notice follows.
     *
     * @return Collection<int, DailyLogEvent>
     */
    public function unnotifiedEvents(Job $job): Collection
    {
        return DailyLogEvent::query()
            ->with('dailyLog')
            ->unnotified()
            ->whereIn('daily_log_id', DailyLog::query()->forJobTree($job)->select('id'))
            ->get();
    }

    /**
     * Hours worked on a job over a period — §17's exposure denominator.
     *
     * §17.6 calls this "the denominator nobody has", and a safety rate without it is a number a company reports because
     * the field was there. Approved diaries only: an unapproved day is not evidence, and a rate computed from drafts
     * would move every time somebody edited one.
     */
    public function exposureHours(Job $job, ?string $from = null, ?string $to = null): float
    {
        $logs = DailyLog::query()
            ->with('manpower')
            ->forJobTree($job)
            ->approved()
            ->when($from, fn ($q) => $q->whereDate('log_date', '>=', Carbon::parse($from)->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('log_date', '<=', Carbon::parse($to)->toDateString()))
            ->get();

        return round((float) $logs->sum(fn (DailyLog $log): float => $log->totalManHours()), 2);
    }

    private function requireOpen(DailyLog $log, string $verb): void
    {
        if ($log->isApproved()) {
            throw new InvalidArgumentException(
                "{$log->displayName()} was approved on {$log->approved_at->toDateString()} and cannot be {$verb}. "
                .'An editable site diary is not evidence — reopen it with a reason if it is wrong.'
            );
        }
    }
}
