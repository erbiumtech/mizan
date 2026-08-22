<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Competency;
use App\Modules\ConstructionQhse\Models\SitePersonnel;
use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use App\Modules\ConstructionQhse\Models\ToolboxTalkAttendee;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The induction register, competencies and toolbox talks — `docs/construction-management-plan.md` §17.5.
 *
 * **A name is all that is ever required**, of a person on the register and of an attendee at a talk. §17.5: "most
 * attendees on most sites are a subcontractor's labourers." A register that asked for more would record the people who
 * happened to be on the payroll, which is a small and unrepresentative subset of the people on site.
 *
 * Five rules live here.
 *
 *  - **An induction has an expiry, and the register distinguishes never-inducted from lapsed.** They are different
 *    conversations, and a single "not inducted" number would hide which one a site has.
 *  - **`is_mandatory` decides whether an expiry is a stoppage.** `notCleared()` is the list a gateman needs: somebody
 *    on site whose induction has run out, or whose mandatory ticket has.
 *  - **Warnings fire once per threshold.** `expiry_notified_at_days` records which has gone out, so a daily run does not
 *    email somebody every morning until they act — because whoever gets a warning every day stops reading them.
 *  - **An attendee's name is snapshotted onto the row even when the register is linked.** A register entry can be renamed
 *    or removed; the attendance record has to keep saying who was actually there.
 *  - **A talk with nobody recorded is named rather than counted as zero attendance.** A talk given and not written up is
 *    a different fact from a talk nobody came to, and only the first is worth chasing.
 */
class SitePersonnelService
{
    /**
     * Put somebody on the register.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(Job $job, array $attributes): SitePersonnel
    {
        if (trim((string) ($attributes['name'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A person needs a name, and a name is all that is required. Most people on most sites are somebody '
                .'else\'s employees, and a register that asked for more would record only the ones on the payroll.'
            );
        }

        return TenantTransaction::run(fn (): SitePersonnel => SitePersonnel::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
        ])));
    }

    /**
     * Record an induction, with the date it runs out.
     *
     * The validity is a parameter rather than a policy constant, because an induction's life is a site rule — twelve
     * months on one job, the duration of the contract on another, and a fresh one after any six-week absence on a third.
     */
    public function induct(SitePersonnel $person, ?string $on = null, ?string $validTo = null): SitePersonnel
    {
        $inductedOn = Carbon::parse($on ?? now())->toDateString();

        if ($validTo !== null && Carbon::parse($validTo)->lt($inductedOn)) {
            throw new InvalidArgumentException('An induction cannot expire before it was given.');
        }

        $person->update([
            'inducted_on' => $inductedOn,
            'inducted_by' => auth()->id(),
            'induction_valid_to' => $validTo,
            'first_on_site' => $person->first_on_site ?? $inductedOn,
        ]);

        return $person->refresh();
    }

    /**
     * Add a ticket.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addCompetency(SitePersonnel $person, array $attributes): Competency
    {
        if (trim((string) ($attributes['title'] ?? '')) === '') {
            throw new InvalidArgumentException('A competency needs a title — what the ticket is for.');
        }

        if (! array_key_exists($attributes['kind'] ?? 'training', Competency::KINDS)) {
            throw new InvalidArgumentException('A competency needs a kind: training, licence, certification, medical or authorisation.');
        }

        if (filled($attributes['issued_on'] ?? null) && filled($attributes['expires_on'] ?? null)
            && Carbon::parse($attributes['expires_on'])->lt(Carbon::parse($attributes['issued_on']))) {
            throw new InvalidArgumentException('A ticket cannot expire before it was issued.');
        }

        return $person->competencies()->create($attributes);
    }

    /**
     * Renew one, which clears the warning ladder.
     *
     * Clearing `expiry_notified_at_days` is the point: a renewed ticket has to be able to warn again next year, and a
     * stale marker would silence it for good.
     */
    public function renewCompetency(Competency $competency, string $expiresOn, ?string $issuedOn = null): Competency
    {
        $competency->update([
            'issued_on' => $issuedOn === null ? $competency->issued_on : Carbon::parse($issuedOn)->toDateString(),
            'expires_on' => Carbon::parse($expiresOn)->toDateString(),
            'expiry_notified_at_days' => null,
        ]);

        return $competency->refresh();
    }

    /** Take somebody off site, which is what makes an expired ticket historical rather than urgent. */
    public function deactivate(SitePersonnel $person, ?string $lastOnSite = null): SitePersonnel
    {
        $person->update([
            'is_active' => false,
            'last_on_site' => Carbon::parse($lastOnSite ?? now())->toDateString(),
        ]);

        return $person->refresh();
    }

    /**
     * **Nobody who should not be working** — the list a gateman needs.
     *
     * Active people whose induction has run out or who never had one, or who hold a lapsed mandatory ticket. Filtered in
     * PHP over the loaded tickets, because the question spans two tables and a per-row flag; the query is narrowed to
     * active personnel first, which is a gate register rather than a history.
     *
     * @return Collection<int, SitePersonnel>
     */
    public function notCleared(Job $job, ?string $asAt = null): Collection
    {
        return SitePersonnel::query()
            ->forJobTree($job)
            ->active()
            ->with('competencies')
            ->get()
            ->reject(fn (SitePersonnel $person): bool => $person->isClearedToWork($asAt))
            ->values();
    }

    /**
     * Never inducted at all, kept apart from lapsed.
     *
     * @return Collection<int, SitePersonnel>
     */
    public function neverInducted(Job $job): Collection
    {
        return SitePersonnel::query()->forJobTree($job)->neverInducted()->get();
    }

    /**
     * Inducted, and it has run out — a different conversation from never having been.
     *
     * @return Collection<int, SitePersonnel>
     */
    public function inductionLapsed(Job $job, ?string $asAt = null): Collection
    {
        return SitePersonnel::query()->forJobTree($job)->inductionLapsed($asAt)->get();
    }

    /**
     * **Induction coverage** — §17.6's leading indicator.
     *
     * Null rather than a percentage where nobody is on the register: a site with an empty register is not 100% inducted,
     * and reporting that would be the flattering wrong answer §17.6's whole section is written against.
     *
     * @return array{on_site: int, inducted: int, percent: float|null}
     */
    public function inductionCoverage(Job $job, ?string $asAt = null): array
    {
        $people = SitePersonnel::query()->forJobTree($job)->active()->get();

        if ($people->isEmpty()) {
            return ['on_site' => 0, 'inducted' => 0, 'percent' => null];
        }

        $inducted = $people->filter(fn (SitePersonnel $person): bool => $person->isInducted($asAt))->count();

        return [
            'on_site' => $people->count(),
            'inducted' => $inducted,
            'percent' => round($inducted / $people->count() * 100, 1),
        ];
    }

    /**
     * Mandatory tickets that have lapsed or are about to, on people still on site.
     *
     * @return Collection<int, Competency>
     */
    public function competenciesNeedingAttention(Job $job, int $withinDays = 30, ?string $asAt = null): Collection
    {
        $personnel = SitePersonnel::query()->forJobTree($job)->active()->select('id');

        return Competency::query()
            ->with('sitePersonnel')
            ->mandatory()
            ->whereIn('site_personnel_id', $personnel)
            ->where(fn ($query) => $query
                ->whereIn('id', Competency::query()->expired($asAt)->select('id'))
                ->orWhereIn('id', Competency::query()->expiring($withinDays, $asAt)->select('id')))
            ->orderBy('expires_on')
            ->get();
    }

    /**
     * Tickets due a warning, with the threshold each has reached.
     *
     * The same shape as §13's notice warnings: the caller sends and then calls `markWarned()`, so a send that fails does
     * not silence the next run.
     *
     * @return Collection<int, array{competency: Competency, threshold: int}>
     */
    public function dueForWarning(?string $asAt = null): Collection
    {
        return Competency::query()
            ->with('sitePersonnel')
            ->whereNotNull('expires_on')
            ->whereIn('site_personnel_id', SitePersonnel::query()->active()->select('id'))
            ->get()
            ->map(fn (Competency $competency): array => [
                'competency' => $competency,
                'threshold' => $competency->warningThreshold($asAt),
            ])
            ->filter(fn (array $row): bool => $row['threshold'] !== null)
            ->values();
    }

    /** Record that a warning went out at a threshold, so the next run does not send it again. */
    public function markWarned(Competency $competency, int $threshold): Competency
    {
        $competency->update(['expiry_notified_at_days' => $threshold]);

        return $competency->refresh();
    }

    /**
     * Record a toolbox talk.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordTalk(Job $job, array $attributes): ToolboxTalk
    {
        if (trim((string) ($attributes['topic'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A toolbox talk needs a topic. "Toolbox talk" against a date is a row that proves a meeting happened '
                .'and says nothing about what anybody was told.'
            );
        }

        if (blank($attributes['delivered_at'] ?? null)) {
            throw new InvalidArgumentException(
                'A toolbox talk needs the time it was given. One at seven in the morning before the shift and one at '
                .'four in the afternoon are different facts about a site.'
            );
        }

        return TenantTransaction::run(fn (): ToolboxTalk => ToolboxTalk::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'reference' => $attributes['reference'] ?? $this->nextTalkReference($job),
            'delivered_at' => Carbon::parse($attributes['delivered_at']),
        ])));
    }

    /** `TBT-1` upward per job. */
    public function nextTalkReference(Job $job): string
    {
        $used = ToolboxTalk::query()
            ->where('job_id', $job->getKey())
            ->pluck('reference')
            ->map(fn (string $reference): int => (int) (preg_match('/(\d+)$/', $reference, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'TBT-'.($used + 1);
    }

    /**
     * Record an attendee.
     *
     * The name is written onto the row even when the register is linked: a register entry can be renamed or removed, and
     * the attendance record has to keep saying who was actually there.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addAttendee(ToolboxTalk $talk, array $attributes): ToolboxTalkAttendee
    {
        $person = filled($attributes['site_personnel_id'] ?? null)
            ? SitePersonnel::query()->find($attributes['site_personnel_id'])
            : null;

        $name = trim((string) ($attributes['name'] ?? $person?->name ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException(
                'An attendee needs a name. A name typed in is enough — most people at most talks are somebody else\'s '
                .'employees.'
            );
        }

        return $talk->attendees()->create(array_merge($attributes, [
            'name' => $name,
            'employer' => $attributes['employer'] ?? $person?->employer,
        ]));
    }

    /**
     * Add everybody currently on the register as attendees.
     *
     * The convenience a foreman actually needs at seven in the morning, and it is safe because it copies the names —
     * so the sheet still says who was there if the register changes afterwards. Skips anybody already recorded, so
     * pressing it twice does not double the count.
     */
    public function addWholeSite(ToolboxTalk $talk): int
    {
        $already = $talk->attendees()->pluck('site_personnel_id')->filter()->all();

        $people = SitePersonnel::query()
            ->where('job_id', $talk->job_id)
            ->active()
            ->whereKeyNot($already)
            ->get();

        foreach ($people as $person) {
            $this->addAttendee($talk, [
                'site_personnel_id' => $person->getKey(),
                'name' => $person->name,
                'employer' => $person->employer,
            ]);
        }

        return $people->count();
    }

    /**
     * **Talks delivered and attended over a period** — §17.6's leading indicator, both halves.
     *
     * Forty talks to two people each is not a briefed site, which is why the attendance figure travels with the count.
     * The talks with nobody recorded are counted separately, because that is a paperwork gap rather than an attendance
     * one.
     *
     * @return array{talks: int, attendances: int, average: float|null, unrecorded: int}
     */
    public function talkCoverage(Job $job, string $from, string $to): array
    {
        $talks = ToolboxTalk::query()
            ->forJobTree($job)
            ->deliveredBetween($from, $to)
            ->with('attendees')
            ->get();

        if ($talks->isEmpty()) {
            return ['talks' => 0, 'attendances' => 0, 'average' => null, 'unrecorded' => 0];
        }

        $attendances = (int) $talks->sum(fn (ToolboxTalk $talk): int => $talk->attendeeCount());

        return [
            'talks' => $talks->count(),
            'attendances' => $attendances,
            'average' => round($attendances / $talks->count(), 1),
            'unrecorded' => $talks->filter(fn (ToolboxTalk $talk): bool => $talk->hasNoAttendees())->count(),
        ];
    }
}
