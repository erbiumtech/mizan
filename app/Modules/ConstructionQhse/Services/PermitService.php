<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Permit;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Permits to work — `docs/construction-management-plan.md` §17.5.
 *
 * **"A permit is time-boxed, and an expired-but-open permit is the failure mode that kills people."** Six rules follow.
 *
 *  - **An extension is a new row.** `extend()` creates a permit pointing back at the one it extends and closes the
 *    original; nothing here writes `valid_to` on an issued permit. §17.5: "overwriting `valid_to` destroys the record of
 *    what was authorised when" — and a regulator asks what was authorised *at the moment something happened*.
 *  - **A permit cannot be issued for a window that has already closed.** Issuing one is authorising work, and
 *    authorising work in the past is either a mistake or a back-dated cover.
 *  - **A permit is closed with the area made safe, or with a reason it was not.** A hot-work permit closed without the
 *    area being checked is the sequence that burns a building down an hour after everybody goes home.
 *  - **Suspension and resumption are recorded rather than implied.** A permit suspended because the wind got up and then
 *    resumed is a different history from one that ran uninterrupted.
 *  - **Nothing auto-closes an expired permit.** It would be the obvious convenience and it is exactly wrong: a permit
 *    quietly marked closed by a scheduled job is a hazard nobody walked back to. Expiry makes it *visible*, and a person
 *    closes it.
 *  - **The type-specific detail is validated against the type**, so a confined-space permit with no rescue plan and a
 *    hot-work permit with no fire watch are refused at issue rather than found afterwards.
 */
class PermitService
{
    /**
     * The detail keys each type must carry before it may be issued.
     *
     * Not a schema for the JSON bag — the bag is deliberately open — but the handful of fields whose *absence* is the
     * thing that gets somebody hurt. Every regime's procedure asks for these, and a permit issued without them is a
     * permit that was not thought about.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_DETAILS = [
        'hot_work' => ['fire_watch', 'extinguisher_present'],
        'confined_space' => ['rescue_plan', 'gas_test'],
        'excavation' => ['services_scanned'],
        'electrical_isolation' => ['isolation_certificate'],
        'lifting_operation' => ['lift_plan'],
        'live_services' => ['isolation_certificate'],
        'radiography' => ['exclusion_zone'],
        'diving' => ['rescue_plan'],
        'pressure_testing' => ['exclusion_zone'],
    ];

    /**
     * Raise a permit as a draft.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function draft(Job $job, array $attributes): Permit
    {
        if (! array_key_exists($attributes['type'] ?? '', Permit::TYPES)) {
            throw new InvalidArgumentException(
                'A permit needs a type. The list is a safety vocabulary rather than project reference data — every one '
                .'of them has a procedure behind it.'
            );
        }

        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A permit needs describing. "Hot work" against a level is a document the person doing the work cannot '
                .'check themselves against.'
            );
        }

        foreach (['valid_from', 'valid_to'] as $field) {
            if (blank($attributes[$field] ?? null)) {
                throw new InvalidArgumentException(
                    'A permit needs both ends of its window, with times. A permit valid "on the 20th" authorises hot '
                    .'work at four in the morning, which is the failure this application refuses to make possible.'
                );
            }
        }

        $from = Carbon::parse($attributes['valid_from']);
        $to = Carbon::parse($attributes['valid_to']);

        if ($to->lte($from)) {
            throw new InvalidArgumentException('A permit cannot end before it starts.');
        }

        return TenantTransaction::run(fn (): Permit => Permit::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'permit_number' => $attributes['permit_number'] ?? $this->nextNumber($job),
            'valid_from' => $from,
            'valid_to' => $to,
        ])));
    }

    /** `PTW-1` upward per job, off the trailing digits so an imported register continues rather than colliding. */
    public function nextNumber(Job $job): string
    {
        $used = Permit::query()
            ->where('job_id', $job->getKey())
            ->pluck('permit_number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'PTW-'.($used + 1);
    }

    /**
     * Edit a draft. An issued permit is a document somebody is working under.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Permit $permit, array $attributes): Permit
    {
        if (! $permit->isDraft()) {
            throw new InvalidArgumentException(
                "{$permit->displayName()} is {$permit->status} and somebody may be working under it. Extend it or "
                .'close it and raise another — editing the window of a live permit is how the record of what was '
                .'authorised gets lost.'
            );
        }

        unset($attributes['status'], $attributes['issued_at'], $attributes['closed_at'], $attributes['extends_permit_id']);

        $permit->update($attributes);

        return $permit->refresh();
    }

    /**
     * **Issue it — the act that authorises work.**
     *
     * Two refusals, and both are about not authorising something nobody checked:
     *
     *  - **A window that has already closed.** Issuing a permit is authorising work; authorising work in the past is
     *    either a mistake or a back-dated cover, and neither should be quiet.
     *  - **Missing the details that type's procedure turns on** — a confined space with no rescue plan, hot work with no
     *    fire watch. Refused here rather than at draft, because a permit half-written is the ordinary state of a draft.
     */
    public function issue(Permit $permit, ?string $at = null): Permit
    {
        if (! $permit->isDraft()) {
            throw new InvalidArgumentException("{$permit->displayName()} is already {$permit->status}.");
        }

        $now = Carbon::parse($at ?? now());

        if ($permit->valid_to->lt($now)) {
            throw new InvalidArgumentException(
                "{$permit->displayName()} expired at {$permit->valid_to->format('D d M H:i')}. Issuing it would "
                .'authorise work that is already over — raise a permit for the window the work is actually in.'
            );
        }

        $missing = collect(self::REQUIRED_DETAILS[$permit->type] ?? [])
            ->reject(fn (string $key): bool => filled($permit->detail($key)));

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException(
                "A {$permit->typeLabel()} permit needs ".$missing->map(
                    fn (string $key): string => str_replace('_', ' ', $key),
                )->implode(', ').' recorded before it can be issued. These are the fields the procedure for this kind '
                .'of work turns on, and a permit issued without them is a permit nobody thought about.'
            );
        }

        $permit->update([
            'status' => Permit::STATUS_ISSUED,
            'issued_by' => auth()->id(),
            'issued_at' => $now,
        ]);

        return $permit->refresh();
    }

    /**
     * Record that whoever is doing the work accepted it.
     *
     * A permit nobody accepted is a piece of paper rather than an authorisation, and the gap is invisible unless both
     * stamps are kept.
     */
    public function accept(Permit $permit, string $acceptedBy, ?string $at = null): Permit
    {
        if ($permit->status !== Permit::STATUS_ISSUED) {
            throw new InvalidArgumentException(
                "{$permit->displayName()} is {$permit->status}, so there is nothing to accept."
            );
        }

        if (trim($acceptedBy) === '') {
            throw new InvalidArgumentException(
                'Acceptance needs a name. A permit accepted by nobody is a permit nobody is answerable for.'
            );
        }

        $permit->update([
            'accepted_by_label' => $acceptedBy,
            'accepted_at' => Carbon::parse($at ?? now()),
        ]);

        return $permit->refresh();
    }

    /**
     * **Suspend it** — stop the work without ending the authorisation.
     *
     * Recorded with a reason and a time, because a permit suspended because the wind got up and then resumed is a
     * different history from one that ran uninterrupted, and that history is what an investigation reads.
     */
    public function suspend(Permit $permit, string $reason, ?string $at = null): Permit
    {
        if ($permit->status !== Permit::STATUS_ISSUED) {
            throw new InvalidArgumentException("{$permit->displayName()} is {$permit->status}, so it cannot be suspended.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Suspending a permit needs a reason. Whoever comes back to it needs to know what has to change before '
                .'work restarts.'
            );
        }

        $permit->update([
            'status' => Permit::STATUS_SUSPENDED,
            'suspended_at' => Carbon::parse($at ?? now()),
            'suspended_by' => auth()->id(),
            'suspension_reason' => $reason,
            'resumed_at' => null,
        ]);

        return $permit->refresh();
    }

    /** Resume it, which is refused once the window has closed — that is an extension, not a resumption. */
    public function resume(Permit $permit, ?string $at = null): Permit
    {
        if (! $permit->isSuspended()) {
            throw new InvalidArgumentException("{$permit->displayName()} is not suspended.");
        }

        $now = Carbon::parse($at ?? now());

        if ($permit->valid_to->lt($now)) {
            throw new InvalidArgumentException(
                "{$permit->displayName()} expired at {$permit->valid_to->format('D d M H:i')} while it was suspended. "
                .'Resuming it would authorise work outside the window somebody signed for — extend it instead, which '
                .'records a new authorisation rather than quietly stretching the old one.'
            );
        }

        $permit->update(['status' => Permit::STATUS_ISSUED, 'resumed_at' => $now]);

        return $permit->refresh();
    }

    /**
     * **Extend it: a new permit pointing back at this one.**
     *
     * §17.5: "an extension is a new row pointing back at the one it extends; overwriting `valid_to` destroys the record
     * of what was authorised when." The original is closed at the moment the extension takes over, so the two windows do
     * not overlap and the register can say which authorisation covered any given minute.
     *
     * The extension starts as a draft, because extending is a request and issuing is still somebody's decision.
     */
    public function extend(Permit $permit, string $newValidTo, ?string $reason = null): Permit
    {
        if ($permit->isClosed()) {
            throw new InvalidArgumentException(
                "{$permit->displayName()} is {$permit->status}. Raise a new permit rather than extending a closed one — "
                .'the work has stopped and been signed off.'
            );
        }

        $to = Carbon::parse($newValidTo);

        if ($to->lte($permit->valid_to)) {
            throw new InvalidArgumentException(
                'An extension has to end after the permit it extends. Shortening a window is a suspension or a '
                .'close-out, not an extension.'
            );
        }

        return TenantTransaction::run(function () use ($permit, $to, $reason): Permit {
            $extension = Permit::create([
                'job_id' => $permit->job_id,
                'contract_id' => $permit->contract_id,
                'permit_number' => $permit->permit_number.'-EXT'.($permit->extensions()->count() + 1),
                'type' => $permit->type,
                'description' => $permit->description,
                'location_id' => $permit->location_id,
                'location_detail' => $permit->location_detail,
                'activity' => $permit->activity,
                // The extension picks up where the original left off, so no minute is covered twice or not at all.
                'valid_from' => $permit->valid_to,
                'valid_to' => $to,
                'requester_label' => $permit->requester_label,
                'persons_count' => $permit->persons_count,
                'details' => $permit->details,
                'extends_permit_id' => $permit->getKey(),
                'status' => Permit::STATUS_DRAFT,
            ]);

            /*
             * The original closes at its own end time, and the area is *not* asserted safe: work is continuing under the
             * extension, so claiming the area was made safe would be a false record of a walk nobody did.
             */
            $permit->update([
                'status' => Permit::STATUS_CLOSED,
                'closed_at' => $permit->valid_to,
                'closed_by' => auth()->id(),
                'area_made_safe' => false,
                'close_out_notes' => trim('Extended by '.$extension->permit_number.'. '.($reason ?? '')),
            ]);

            return $extension->refresh();
        });
    }

    /**
     * **Close it out, with the area made safe or a reason it was not.**
     *
     * A hot-work permit closed without the area being checked is the sequence that burns a building down an hour after
     * everybody goes home. So the flag is asked for, and a closure without it needs a sentence saying why — which is
     * strictly better than refusing, because a permit that cannot be closed is a permit that stays open for ever and the
     * expired-and-open list becomes noise.
     */
    public function close(Permit $permit, bool $areaMadeSafe, ?string $notes = null, ?string $at = null): Permit
    {
        if ($permit->isClosed()) {
            throw new InvalidArgumentException("{$permit->displayName()} is already {$permit->status}.");
        }

        if (! $areaMadeSafe && trim((string) $notes) === '') {
            throw new InvalidArgumentException(
                'Closing a permit without the area made safe needs a reason. A permit closed with nobody having walked '
                .'the area is the sequence that burns a building down an hour after everybody has gone home.'
            );
        }

        $permit->update([
            'status' => Permit::STATUS_CLOSED,
            'closed_at' => Carbon::parse($at ?? now()),
            'closed_by' => auth()->id(),
            'area_made_safe' => $areaMadeSafe,
            'close_out_notes' => $notes,
        ]);

        return $permit->refresh();
    }

    /** Cancel a permit that was never used, with a reason. */
    public function cancel(Permit $permit, string $reason): Permit
    {
        if ($permit->isClosed()) {
            throw new InvalidArgumentException("{$permit->displayName()} is already {$permit->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancelling a permit needs a reason.');
        }

        $permit->update([
            'status' => Permit::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'closed_at' => now(),
            'closed_by' => auth()->id(),
        ]);

        return $permit->refresh();
    }

    /**
     * **Expired and still open** — the failure mode §17.5 names, as a list.
     *
     * Nothing auto-closes these, and that is deliberate: a permit quietly marked closed by a scheduled job is a hazard
     * nobody walked back to. Expiry makes it visible and a person closes it.
     *
     * @return Collection<int, Permit>
     */
    public function expiredAndOpen(Job $job, ?string $asAt = null): Collection
    {
        return Permit::query()
            ->forJobTree($job)
            ->expiredAndOpen($asAt)
            ->orderBy('valid_to')
            ->get();
    }

    /**
     * What is authorising work right now — the board a site manager wants at seven in the morning.
     *
     * @return Collection<int, Permit>
     */
    public function inForce(Job $job, ?string $asAt = null): Collection
    {
        return Permit::query()->forJobTree($job)->inForceAt($asAt)->orderBy('valid_to')->get();
    }

    /**
     * Issued and never accepted — a piece of paper rather than an authorisation.
     *
     * @return Collection<int, Permit>
     */
    public function issuedButNotAccepted(Job $job): Collection
    {
        return Permit::query()
            ->forJobTree($job)
            ->authorising()
            ->whereNotNull('issued_at')
            ->whereNull('accepted_at')
            ->get();
    }

    /**
     * Closed without anybody saying the area was made safe.
     *
     * Allowed at close-out with a reason, and worth a list afterwards: it is a pattern rather than an event, and a site
     * where this is common is a site where the close-out is a signature.
     *
     * @return Collection<int, Permit>
     */
    public function closedWithoutMakingSafe(Job $job): Collection
    {
        return Permit::query()
            ->forJobTree($job)
            ->where('status', Permit::STATUS_CLOSED)
            ->where('area_made_safe', false)
            // An extension closes its parent without a walk by design; that is not the pattern this is looking for.
            ->whereDoesntHave('extensions')
            ->get();
    }

    /**
     * The proportion of permits closed on time — §17.6's leading indicator.
     *
     * "On time" means closed before the window ended. Null rather than a percentage where nothing has been closed, for
     * the reason §17.6 gives about every rate here: 100% on a site that has closed nothing is the most flattering wrong
     * answer available.
     */
    public function closedOnTimeRate(Job $job, ?string $from = null, ?string $to = null): ?float
    {
        $closed = Permit::query()
            ->forJobTree($job)
            ->where('status', Permit::STATUS_CLOSED)
            ->whereNotNull('closed_at')
            ->when($from && $to, fn ($q) => $q
                ->where('valid_to', '>=', Carbon::parse($from)->startOfDay())
                ->where('valid_to', '<=', Carbon::parse($to)->endOfDay()))
            ->get();

        if ($closed->isEmpty()) {
            return null;
        }

        $onTime = $closed->filter(fn (Permit $permit): bool => $permit->closed_at->lte($permit->valid_to))->count();

        return round($onTime / $closed->count() * 100, 1);
    }
}
