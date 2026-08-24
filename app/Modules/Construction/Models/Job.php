<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Concerns\HasMaterialisedPath;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contract to build one thing at one place.
 *
 * `docs/construction-management-plan.md` §1. **The model is `Job` and never `Project`** — §1.1's first rule,
 * and the reason is that a company licensing both has a `Project` (a software engagement with environments
 * and a status page) and a `Job` (a building site), which are unrelated tables with similar-sounding names.
 * The other two rules of §1.1 live elsewhere: the per-tenant label is a company setting, and the navigation
 * domain keeps the two out of one menu.
 *
 * Comments and custom fields are polymorphic and free, so an RFI or a job needs no per-module notes table.
 */
class Job extends Model
{
    use Auditable;
    use HasComments;
    use HasMaterialisedPath;

    /**
     * Named explicitly, and this is not cosmetic.
     *
     * Eloquent would derive `jobs` from the class name — **which is Laravel's own queue table, and it
     * exists**. So a missing `$table` here does not fail loudly: it reads and writes the queue table, and the
     * first symptom is `table jobs has no column named code`. Anything that happened to match would be worse.
     *
     * The generic class name is §1.1's ("the model is `Job` and never `Project`"), and this is the cost of it.
     * The alternative was `ConstructionJob`, which derives the right table by itself and matches §18.2's own
     * example; if that is preferred it is a cheap change while no tenant holds a row.
     */
    protected $table = 'construction_jobs';

    public const STATUS_TENDER = 'tender';

    public const STATUS_AWARDED = 'awarded';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STANDARD_FIDIC = 'fidic';

    public const STANDARD_AIA = 'aia';

    public const STANDARD_CUSTOM = 'custom';

    /**
     * The statuses a job is not being worked on in.
     *
     * Named rather than inlined because "is this job live" is asked by the cost report, the certificate
     * screen and the job picker, and three different `whereNotIn` lists would drift.
     */
    public const DORMANT_STATUSES = ['closed', 'cancelled', 'lost'];

    protected $fillable = [
        'code', 'name', 'description', 'parent_id', 'path', 'client_contact_id', 'project_id', 'stock_location_id',
        'nature', 'status', 'contract_standard', 'currency_code',
        'site_address_line_1', 'site_address_line_2', 'site_city', 'site_country', 'latitude', 'longitude',
        'commencement_date', 'planned_completion_date', 'revised_completion_date', 'actual_completion_date',
        'substantial_completion_date', 'defects_period_days', 'final_certificate_date',
        'contract_sum', 'retention_pct', 'retention_cap_pct', 'retention_first_release_pct',
        'advance_payment_pct', 'advance_recovery_start_pct', 'advance_recovery_rate_pct',
        'liquidated_damages_per_day', 'liquidated_damages_cap_pct', 'payment_terms_days',
        'certifier_contact_id', 'manager_employee_id', 'qs_employee_id', 'site_agent_employee_id',
        'closed_at',
        // §17.6: which source a job's safety exposure hours come from — the site diary or Timesheets, never both.
        // Counting both halves every frequency rate, and a halved rate is worse than a missing one because it looks
        // like a number somebody can act on. Nullable, and the null is a state the safety report has to name.
        'exposure_hours_source',
        // §4.4: how this job's percent complete is measured — cost-to-cost, surveyed or milestone. Nullable with no
        // default, because choosing for a company would pick the answer that flatters an over-spending job: cost-to-cost
        // reports more progress for spending more money, which is exactly backwards.
        'percent_complete_method',
    ];

    /**
     * The same defaults the migration carries, so the in-memory model agrees with the row.
     *
     * Without these a caller doing `Job::create([...])->status` reads **null** while the database holds
     * `tender` — the model is not refreshed after an insert. `PayComponent` sets its defaults the same way and
     * for the same reason.
     */
    protected $attributes = [
        'status' => self::STATUS_TENDER,
        'contract_standard' => self::STANDARD_FIDIC,
        'nature' => 'building',
    ];

    protected $casts = [
        'commencement_date' => 'date',
        'planned_completion_date' => 'date',
        'revised_completion_date' => 'date',
        'actual_completion_date' => 'date',
        'substantial_completion_date' => 'date',
        'final_certificate_date' => 'date',
        'closed_at' => 'datetime',
        'contract_sum' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /*
     * The materialised path comes from HasMaterialisedPath, shared with WbsNode and Location.
     *
     * It lived here first, and the trait exists because the lifecycle is the subtle part: the first version
     * wrote the path in `saving`, where a new record has no id, so every root job got `/` and the subtree
     * scope matched the entire table. Three trees copying that would have been three chances to repeat it.
     *
     * Jobs are company-wide rather than partitioned, so `pathScopeColumn()` stays null — a job's subtree is
     * every job beneath it, whichever client it belongs to.
     */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_contact_id');
    }

    /**
     * The job's site store — `docs/construction-management-plan.md` §6.
     *
     * Guarded, not required: a job with no store buys everything direct to the work face, which §6 calls the default
     * and which "works with Inventory unlicensed". Every surface offering this checks `modules()->enabled('inventory')`
     * first, so a contractor who tracks no stock never sees it and the column stays null.
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /** The Engineer under FIDIC, the Architect under AIA. */
    public function certifier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'certifier_contact_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::DORMANT_STATUSES);
    }

    public function isFidic(): bool
    {
        return $this->contract_standard === self::STANDARD_FIDIC;
    }

    /**
     * The completion date in force: the revised one when an extension of time has moved it.
     *
     * One accessor rather than `revised_completion_date ?? planned_completion_date` at each call site, because
     * a screen that reads the planned date after an EoT shows a job as late when it is not.
     */
    public function completionDate()
    {
        return $this->revised_completion_date ?? $this->planned_completion_date;
    }
}
