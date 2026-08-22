<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person on one site — `docs/construction-management-plan.md` §17.5.
 *
 * **The name works alone**, and §17.5 is explicit about why: "most attendees on most sites are a subcontractor's
 * labourers." A register that required an employee record would record the inductions of the people who happened to be
 * on the payroll, which is a small and unrepresentative subset of the people on site.
 *
 * **An induction is not a permanent state.** `inducted_on` with `induction_valid_to` is the pair that makes this an
 * induction *register* rather than a list: somebody inducted fourteen months ago on a site whose induction lasts a year
 * is not inducted, and a register that could not say so would report full coverage on a site with none.
 *
 * One row per person per job, because a person inducted on the tower is not inducted on the annexe.
 */
class SitePersonnel extends Model
{
    use Auditable;

    protected $table = 'construction_site_personnel';

    protected $fillable = [
        'job_id', 'name', 'employer', 'trade', 'employee_id', 'contact_id',
        'identification', 'phone',
        'inducted_on', 'inducted_by', 'induction_valid_to',
        'first_on_site', 'last_on_site', 'is_active', 'notes',
    ];

    protected $casts = [
        'inducted_on' => 'date',
        'induction_valid_to' => 'date',
        'first_on_site' => 'date',
        'last_on_site' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** Where there is a contact record. Guarded: Invoicing owns contacts and this module does not require it. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class, 'site_personnel_id')->orderBy('expires_on');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ToolboxTalkAttendee::class, 'site_personnel_id');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Never inducted at all.
     *
     * Separate from *lapsed* on purpose: somebody who has never been through an induction and somebody whose induction
     * ran out are different conversations, and a single "not inducted" number would hide which one a site has.
     */
    public function scopeNeverInducted(Builder $query): Builder
    {
        return $query->active()->whereNull('inducted_on');
    }

    /** Inducted, and the induction has run out. */
    public function scopeInductionLapsed(Builder $query, ?string $asAt = null): Builder
    {
        return $query->active()
            ->whereNotNull('inducted_on')
            ->whereNotNull('induction_valid_to')
            // `whereDate`, because `induction_valid_to` is `date`-cast and therefore stored with a time.
            ->whereDate('induction_valid_to', '<', Carbon::parse($asAt ?? now())->toDateString());
    }

    public function isInducted(?string $asAt = null): bool
    {
        if ($this->inducted_on === null) {
            return false;
        }

        return $this->induction_valid_to === null
            || $this->induction_valid_to->gte(Carbon::parse($asAt ?? now())->startOfDay());
    }

    public function inductionLapsed(?string $asAt = null): bool
    {
        return $this->inducted_on !== null && ! $this->isInducted($asAt);
    }

    /**
     * **A mandatory ticket that has run out, on somebody who is still on site.**
     *
     * The exposure this register carries. `is_mandatory` is what turns an expiry into a stoppage: a first-aid
     * certificate lapsing is a gap, and a confined-space ticket lapsing on somebody who is in a chamber this morning is
     * an emergency.
     *
     * Reads the loaded relation, because every screen asking this is showing the tickets.
     *
     * @return \Illuminate\Support\Collection<int, Competency>
     */
    public function expiredMandatoryCompetencies(?string $asAt = null): \Illuminate\Support\Collection
    {
        return $this->competencies
            ->filter(fn (Competency $competency): bool => $competency->is_mandatory && $competency->hasExpired($asAt))
            ->values();
    }

    /** May this person work today: inducted, and no mandatory ticket lapsed. */
    public function isClearedToWork(?string $asAt = null): bool
    {
        return $this->isInducted($asAt) && $this->expiredMandatoryCompetencies($asAt)->isEmpty();
    }

    public function displayName(): string
    {
        return $this->name.($this->employer ? " ({$this->employer})" : '');
    }
}
