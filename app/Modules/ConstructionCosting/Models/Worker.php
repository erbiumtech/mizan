<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pair of hands on a site — `docs/construction-management-plan.md` §7.1.
 *
 * **This table exists because on a site most hands are not employees.** No payslip, no login, paid weekly through a
 * gang leader. §7.1 states the consequence of the alternative plainly: requiring an `Employee` row per labourer would
 * make Employees a hard dependency of this module and would put three hundred people who are not employed into the HR
 * register, where Leave, Payroll and Lifecycle would then all see them.
 *
 * So `employee_id` is nullable and unconstrained — the same treatment `construction_cost_entries.employee_id` gets —
 * and a company running construction without the HR module records every hour worked all the same. §18.1's row for
 * `employees` says exactly this: "`construction_workers` carries everyone and `created_by` answers ownership".
 *
 * `subcontractor_contact_id` carries who supplies the hands where somebody else does. That is a different fact from
 * §12's subcontract: a gang leader supplying six masons under an hourly arrangement is labour this company directs
 * and costs by the hour, while a subcontract is work somebody else prices and certifies. Both exist on real jobs, and
 * conflating them is how labour-only supply ends up outside the labour cost of the job it was working on.
 */
class Worker extends Model
{
    use Auditable;

    /** On the payroll, with an `employee_id` beside it. */
    public const ENGAGEMENT_EMPLOYEE = 'employee';

    /** Paid directly by this company and not on the payroll — daily-wage, weekly, casual. The commonest case. */
    public const ENGAGEMENT_DIRECT = 'direct';

    /** Supplied through a gang leader or agency, who is the one that gets paid. */
    public const ENGAGEMENT_SUPPLIED = 'supplied';

    /** @var array<string, string> */
    public const ENGAGEMENTS = [
        self::ENGAGEMENT_EMPLOYEE => 'Employee, on the payroll',
        self::ENGAGEMENT_DIRECT => 'Direct, not on the payroll',
        self::ENGAGEMENT_SUPPLIED => 'Supplied by a gang leader or agency',
    ];

    protected $table = 'construction_workers';

    protected $fillable = [
        'code', 'name', 'engagement', 'employee_id', 'subcontractor_contact_id', 'trade_id',
        'national_id', 'phone', 'started_on', 'ended_on', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'started_on' => 'date',
        'ended_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'engagement' => self::ENGAGEMENT_DIRECT,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $worker): void {
            $worker->created_by ??= auth()->id();
        });
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    /** The gang leader or agency who supplies this person, and who is the one that gets paid. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'subcontractor_contact_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(LabourRate::class, 'worker_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this person was engaged on a date.
     *
     * Read from the dates rather than from `is_active`, because "was he on site in March" is asked at exactly the
     * moment somebody disputes a week's hours — and the flag only ever answers about today.
     */
    public function wasEngagedOn(string $date): bool
    {
        if ($this->started_on !== null && $this->started_on->gt($date)) {
            return false;
        }

        return $this->ended_on === null || $this->ended_on->gte($date);
    }

    public function displayName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
