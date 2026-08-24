<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who must be at an ITP point, and what they do there — `docs/construction-management-plan.md` §17.1.
 *
 * **A pivot rather than a column, because "who must attend" is exactly the question a hold point answers**, and one
 * column cannot say *the Engineer witnesses, a third-party laboratory verifies, the Employer approves*. That sentence is
 * what a hold point on a structural pour means, and it has three parties in it.
 *
 * The **role is separate from the party** for the same reason one step down: a hold point can require the Engineer to
 * approve *and* a laboratory to verify, and folding the role into the party would lose which of them the work is
 * actually waiting on.
 */
class ItpActivityParty extends Model
{
    /** @var array<string, string> */
    public const PARTIES = [
        'contractor' => 'Contractor',
        'engineer' => 'Engineer',
        'employer' => 'Employer',
        'consultant' => 'Consultant',
        'subcontractor' => 'Subcontractor',
        'third_party_lab' => 'Third-party laboratory',
        'authority' => 'Authority',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const ROLES = [
        'performs' => 'Performs',
        'witnesses' => 'Witnesses',
        'verifies' => 'Verifies',
        'approves' => 'Approves',
        'informed' => 'Informed only',
    ];

    protected $table = 'construction_itp_activity_parties';

    protected $fillable = [
        'itp_activity_id', 'party', 'role', 'attendance_mandatory', 'contact_id', 'party_label',
    ];

    protected $casts = [
        'attendance_mandatory' => 'boolean',
    ];

    protected $attributes = [
        'role' => 'witnesses',
        'attendance_mandatory' => false,
    ];

    public function itpActivity(): BelongsTo
    {
        return $this->belongsTo(ItpActivity::class, 'itp_activity_id');
    }

    /** The named individual, where there is a contact record. Guarded: Invoicing owns contacts. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function partyLabel(): string
    {
        return self::PARTIES[$this->party] ?? $this->party;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /** `Engineer witnesses (mandatory)` — how an ITP prints it. */
    public function describe(): string
    {
        return $this->partyLabel().' '.strtolower($this->roleLabel())
            .($this->attendance_mandatory ? ' (mandatory)' : '');
    }
}
