<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Models\ItpActivityParty;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Inspection and test plans — `docs/construction-management-plan.md` §17.1.
 *
 * **An ITP is a controlled document.** A certification body asks which revision was in force when a given inspection was
 * carried out, so the rules here are about issue and revision rather than about content:
 *
 *  - **A plan is editable while it is a draft and frozen once issued.** An issued ITP is a document somebody is working
 *    to; editing it under them is how an inspection ends up citing acceptance criteria that were never published.
 *  - **A revision is a new plan that supersedes the old one**, not an edit. `revise()` copies the rows, points the new
 *    plan at the next revision letter, and marks the old one superseded — so an inspection carried out last month still
 *    points at the document that was in force last month.
 *  - **A hold point with mandatory attendance and no party is refused.** §17.1's pivot exists because "who must attend"
 *    is exactly the question a hold point answers; a hold point that names nobody is a stoppage waiting for no-one.
 */
class ItpService
{
    /**
     * Draft a plan.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function draft(Job $job, array $attributes): Itp
    {
        foreach (['reference' => 'a reference', 'title' => 'a title'] as $field => $what) {
            if (trim((string) ($attributes[$field] ?? '')) === '') {
                throw new InvalidArgumentException(
                    "An ITP needs {$what}. It is quoted in correspondence and printed on every check sheet raised "
                    .'against it.'
                );
            }
        }

        return TenantTransaction::run(fn (): Itp => Itp::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
        ])));
    }

    /**
     * Edit it, refused once issued.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Itp $itp, array $attributes): Itp
    {
        if (! $itp->isEditable()) {
            throw new InvalidArgumentException(
                "{$itp->displayName()} is {$itp->status} and people are working to it. Revise it instead — an issued "
                .'plan edited under somebody is how an inspection ends up citing criteria that were never published.'
            );
        }

        unset($attributes['status'], $attributes['approved_by'], $attributes['approved_on']);

        $itp->update($attributes);

        return $itp->refresh();
    }

    /**
     * Add a row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addActivity(Itp $itp, array $attributes): ItpActivity
    {
        if (! $itp->isEditable()) {
            throw new InvalidArgumentException(
                "{$itp->displayName()} is {$itp->status} and takes no new rows. Revise it to add a point."
            );
        }

        if (trim((string) ($attributes['activity_description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An ITP row needs describing. It is what the attending party reads before they travel to site.'
            );
        }

        if (! array_key_exists($attributes['point_type'] ?? '', ItpActivity::POINT_TYPES)) {
            throw new InvalidArgumentException(
                'An ITP row needs a point type. Hold, witness and review are the entire reason the document exists — a '
                .'row without one is a formality.'
            );
        }

        $attributes['sequence'] ??= (int) $itp->activities()->max('sequence') + 10;

        return $itp->activities()->create($attributes);
    }

    /**
     * Name a party at a point.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addParty(ItpActivity $activity, array $attributes): ItpActivityParty
    {
        foreach ([
            ['party', ItpActivityParty::PARTIES, 'a party'],
            ['role', ItpActivityParty::ROLES, 'a role'],
        ] as [$key, $allowed, $what]) {
            if (! array_key_exists($attributes[$key] ?? '', $allowed)) {
                throw new InvalidArgumentException(
                    "An ITP point needs {$what}. \"The Engineer witnesses and a laboratory verifies\" is one point and "
                    .'two rows, which is why this is a pivot rather than a column.'
                );
            }
        }

        return $activity->parties()->create($attributes);
    }

    /**
     * Issue the plan — after which it is frozen and inspections may cite it.
     *
     * **A hold point with mandatory attendance and nobody named is refused here**, at the moment it stops being a draft.
     * Refusing at row level would block a plan halfway through being written, which is when a hold point legitimately
     * has no parties yet.
     */
    public function issue(Itp $itp, ?string $on = null): Itp
    {
        if ($itp->status !== Itp::STATUS_DRAFT) {
            throw new InvalidArgumentException("{$itp->displayName()} is already {$itp->status}.");
        }

        $itp->load('activities.parties');

        if ($itp->activities->isEmpty()) {
            throw new InvalidArgumentException(
                "{$itp->displayName()} has no rows. An ITP with no points is a cover sheet."
            );
        }

        $unnamed = $itp->activities
            ->filter(fn (ItpActivity $a): bool => $a->isHoldPoint() && $a->parties->isEmpty())
            ->map(fn (ItpActivity $a): string => (string) $a->sequence);

        if ($unnamed->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Hold point(s) '.$unnamed->implode(', ').' name nobody. A hold point stops work until somebody '
                .'attends, so a hold point with no party is a stoppage waiting for no-one — name the party, or make it '
                .'a review point.'
            );
        }

        $itp->update([
            'status' => Itp::STATUS_ISSUED,
            'issued_on' => Carbon::parse($on ?? now())->toDateString(),
            'revision' => $itp->revision ?? 'A',
        ]);

        return $itp->refresh();
    }

    /** Approve an issued plan, which is the state a certification audit reads for. */
    public function approve(Itp $itp, ?string $on = null): Itp
    {
        if ($itp->status !== Itp::STATUS_ISSUED) {
            throw new InvalidArgumentException(
                "{$itp->displayName()} is {$itp->status}. A plan is issued first and approved second — approving a "
                .'draft would approve something that is still being written.'
            );
        }

        $itp->update([
            'status' => Itp::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_on' => Carbon::parse($on ?? now())->toDateString(),
        ]);

        return $itp->refresh();
    }

    /**
     * **Revise it: a new plan that supersedes the old one, not an edit.**
     *
     * The rows are copied with their parties, so the new draft starts from what was in force rather than from nothing.
     * The old plan is marked superseded and keeps its inspections — an inspection carried out last month still points at
     * the document that was in force last month, which is the whole reason ITPs carry revisions.
     */
    public function revise(Itp $itp): Itp
    {
        if ($itp->isSuperseded()) {
            throw new InvalidArgumentException("{$itp->displayName()} is already superseded.");
        }

        $itp->load('activities.parties');

        return TenantTransaction::run(function () use ($itp): Itp {
            $next = Itp::create([
                'job_id' => $itp->job_id,
                'contract_id' => $itp->contract_id,
                'reference' => $itp->reference.'-'.$this->nextRevision($itp),
                'title' => $itp->title,
                'scope' => $itp->scope,
                'discipline' => $itp->discipline,
                'wbs_node_id' => $itp->wbs_node_id,
                'revision' => $this->nextRevision($itp),
                'status' => Itp::STATUS_DRAFT,
                'notes' => $itp->notes,
            ]);

            foreach ($itp->activities as $activity) {
                $copy = $next->activities()->create($activity->only([
                    'sequence', 'activity_description', 'reference_standard', 'acceptance_criteria',
                    'inspection_method', 'frequency', 'record_form', 'point_type', 'notice_hours', 'is_active',
                ]));

                foreach ($activity->parties as $party) {
                    $copy->parties()->create($party->only([
                        'party', 'role', 'attendance_mandatory', 'contact_id', 'party_label',
                    ]));
                }
            }

            $itp->update(['status' => Itp::STATUS_SUPERSEDED]);

            return $next->refresh();
        });
    }

    /** `A` then `B` — the letter series a drawing office uses, so an ITP reads like the rest of the paperwork. */
    private function nextRevision(Itp $itp): string
    {
        $current = strtoupper((string) ($itp->revision ?? 'A'));

        return preg_match('/^[A-Y]$/', $current) ? chr(ord($current) + 1) : $current.'1';
    }

    /**
     * The plans a job is actually working to.
     *
     * @return Collection<int, Itp>
     */
    public function inForce(Job $job): Collection
    {
        return Itp::query()->forJobTree($job)->inForce()->with('activities')->orderBy('reference')->get();
    }

    /**
     * Every hold point across a job's plans — how much attendance the quality plan is committing to.
     *
     * @return Collection<int, ItpActivity>
     */
    public function holdPoints(Job $job): Collection
    {
        return ItpActivity::query()
            ->active()
            ->holdPoints()
            ->whereIn('itp_id', Itp::query()->forJobTree($job)->inForce()->select('id'))
            ->with('parties')
            ->get();
    }
}
