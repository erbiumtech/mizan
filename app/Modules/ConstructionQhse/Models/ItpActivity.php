<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of an ITP — `docs/construction-management-plan.md` §17.1.
 *
 * **`point_type` is the entire reason an ITP exists.** §17.1: "a **hold** point means work may not proceed past it; a
 * **witness** point means a party is invited and work may proceed if they do not attend; a **review** point is
 * documentation only. Collapsing them into a checkbox turns the document into a formality."
 *
 * Those three sentences are three different commercial positions. A hold point missed is work that has to be opened up.
 * A witness point missed is work that proceeded lawfully because the other party did not attend — which is the
 * contractor's protection and is lost the moment the two are stored the same way.
 *
 * `notice_hours` lives here rather than in configuration because it is a term of *this* plan for *this* activity: 24
 * hours for a rebar inspection and a week for a third-party load test.
 */
class ItpActivity extends Model
{
    public const POINT_HOLD = 'hold';

    public const POINT_WITNESS = 'witness';

    public const POINT_REVIEW = 'review';

    /** @var array<string, string> */
    public const POINT_TYPES = [
        self::POINT_HOLD => 'Hold — work may not proceed',
        self::POINT_WITNESS => 'Witness — party invited, work may proceed',
        self::POINT_REVIEW => 'Review — documentation only',
        'surveillance' => 'Surveillance',
        'monitor' => 'Monitor',
    ];

    protected $table = 'construction_itp_activities';

    protected $fillable = [
        'itp_id', 'sequence', 'activity_description', 'reference_standard', 'acceptance_criteria',
        'inspection_method', 'frequency', 'record_form', 'point_type', 'notice_hours', 'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'notice_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'point_type' => self::POINT_REVIEW,
        'sequence' => 0,
        'is_active' => true,
    ];

    public function itp(): BelongsTo
    {
        return $this->belongsTo(Itp::class, 'itp_id');
    }

    /**
     * Who must attend — a pivot, because one column cannot hold the sentence a hold point means.
     *
     * Ordered as entered, so the plan prints the same way twice. An unordered relation reads back in whatever order the
     * database felt like, and an ITP is a document somebody compares against last month's copy.
     */
    public function parties(): HasMany
    {
        return $this->hasMany(ItpActivityParty::class, 'itp_activity_id')->orderBy('id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'itp_activity_id');
    }

    public function scopeHoldPoints(Builder $query): Builder
    {
        return $query->where('point_type', self::POINT_HOLD);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** **Work may not proceed past this.** The one question this table exists to answer. */
    public function isHoldPoint(): bool
    {
        return $this->point_type === self::POINT_HOLD;
    }

    /** A party is invited and work proceeds without them — which is the contractor's protection. */
    public function isWitnessPoint(): bool
    {
        return $this->point_type === self::POINT_WITNESS;
    }

    /** Whether anybody's attendance is actually compulsory at this point. */
    public function hasMandatoryAttendance(): bool
    {
        return $this->parties->contains(fn (ItpActivityParty $party): bool => $party->attendance_mandatory);
    }

    public function pointLabel(): string
    {
        return self::POINT_TYPES[$this->point_type] ?? $this->point_type;
    }

    public function displayName(): string
    {
        return $this->sequence.'. '.str($this->activity_description)->limit(60);
    }
}
