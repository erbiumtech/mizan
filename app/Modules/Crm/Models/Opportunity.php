<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

/**
 * A deal.
 *
 * **The exactly-one-party rule is this class's reason to exist, and it is docs/crms-plan.md
 * §1's whole architecture made enforceable.** An opportunity belongs to exactly one of a
 * lead or a contact:
 *
 *  - new business has `lead_id` until the lead converts;
 *  - a repeat deal against an existing customer has `contact_id`;
 *  - conversion fills `contact_id` and **keeps `lead_id`** as the origin, because "where
 *    did this customer come from" is a lead-source question asked years later.
 *
 * Both null is a deal about nobody. Both set — other than via conversion — is two answers
 * to "who is this deal with", and the invoice would eventually disagree with the pipeline.
 * Asserted in `booted()` rather than hoped for, the way ContactPerson asserts one primary
 * per contact.
 *
 * **The exchange rate is stored, not read live.** A forecast in mixed currencies has to be
 * summed at some rate, and §13 is explicit: anyone who "fixes" this to read today's rate
 * will silently rewrite last quarter's forecast.
 */
class Opportunity extends Model
{
    use Auditable;
    use HasCustomFields;
    use StoresPlainDates;

    protected array $plainDates = ['expected_close_on', 'closed_on'];

    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    protected $fillable = [
        'title', 'pipeline_id', 'pipeline_stage_id', 'lead_id', 'contact_id',
        'owner_employee_id', 'amount', 'currency_code', 'exchange_rate',
        'probability_pct', 'expected_close_on', 'closed_on', 'outcome',
        'lost_reason_id', 'project_id', 'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'probability_pct' => 'integer',
        'expected_close_on' => 'date',
        'closed_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $opportunity): void {
            $opportunity->assertExactlyOneParty();
        });

        static::creating(function (self $opportunity): void {
            $opportunity->created_by ??= auth()->id();

            // Inherited from the stage when the deal is created, and overridable
            // afterwards: a salesperson who knows this one is 90% should be able to say so
            // without moving every other deal in the stage.
            if ($opportunity->probability_pct === null) {
                $opportunity->probability_pct = $opportunity->stage?->probability_pct ?? 0;
            }
        });
    }

    /**
     * Exactly one of a lead or a contact — unless the lead converted, which is the one
     * case where both are legitimately set.
     *
     * The converted case is recognised by asking the LEAD whether it converted into this
     * contact, rather than by a flag on the deal. A flag would be a second place to keep in
     * step, and it is the lead that knows.
     */
    private function assertExactlyOneParty(): void
    {
        $hasLead = $this->lead_id !== null;
        $hasContact = $this->contact_id !== null;

        if (! $hasLead && ! $hasContact) {
            throw new InvalidArgumentException(
                'A deal needs a lead or a customer. One with neither is a deal about nobody.'
            );
        }

        if ($hasLead && $hasContact) {
            $lead = $this->lead ?? Lead::find($this->lead_id);

            if ($lead?->converted_contact_id !== $this->contact_id) {
                throw new InvalidArgumentException(
                    'A deal belongs to a lead or a customer, not both. Both are set only once the lead '
                    .'has converted into that customer — otherwise the pipeline and the invoice would '
                    .'eventually disagree about who this deal is with.'
                );
            }
        }
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Guarded: null at a company without `invoicing`. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** Guarded on `employees`, so EmployeeAccess scoping applies unchanged. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class, 'lost_reason_id');
    }

    /** Guarded hand-offs, both filled by a human action in phase 6. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(OpportunityStageHistory::class)->orderBy('moved_at');
    }

    /**
     * The CRM timeline — calls, meetings, emails.
     *
     * **Named `timeline()` and not `activities()` on purpose.** The Auditable trait brings in
     * spatie's LogsActivity, which already defines `activities()` as its own morphMany to the
     * `activity_log` table. Naming this one `activities()` silently SHADOWED that, so every
     * audit-trail read on a deal quietly returned CRM calls instead — found by a test that
     * counted three where it expected two.
     */
    public function timeline(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->orderByDesc('occurred_at');
    }

    public function nextActions(): MorphMany
    {
        return $this->morphMany(NextAction::class, 'subject');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('outcome');
    }

    public function scopeWon(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_WON);
    }

    public function scopeLost(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_LOST);
    }

    public function isOpen(): bool
    {
        return $this->outcome === null;
    }

    public function isWon(): bool
    {
        return $this->outcome === self::OUTCOME_WON;
    }

    /** What to call the party, whichever kind it is. */
    public function partyLabel(): string
    {
        return $this->contact?->name
            ?? $this->lead?->display_label
            ?? 'Unknown';
    }

    /**
     * The amount in the company's own currency, at the rate recorded on this deal.
     *
     * A rate of null or zero means the deal is already in the base currency. Deliberately
     * NOT falling back to today's rate: a forecast that moved when the market did would
     * rewrite what was reported last quarter.
     */
    public function baseAmount(): float
    {
        $rate = (float) $this->exchange_rate;

        return $rate > 0
            ? round((float) $this->amount * $rate, 2)
            : round((float) $this->amount, 2);
    }

    /** The forecast contribution: base amount weighted by probability. */
    public function weightedAmount(): float
    {
        return round($this->baseAmount() * ((int) $this->probability_pct / 100), 2);
    }

    /** An open deal with nothing planned — the one thing §3 says to surface as a problem. */
    public function hasOpenNextAction(): bool
    {
        return $this->nextActions()->whereNull('completed_at')->exists();
    }

    /**
     * Whether this deal has sat in its stage past the stage's patience.
     *
     * Measured from the last stage move, falling back to creation for a deal that has never
     * moved — which is the deal most worth surfacing.
     */
    public function isRotting(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $after = $this->stage?->rotsAfter();

        if ($after === null) {
            return false;
        }

        $since = $this->stageHistory()->latest('moved_at')->value('moved_at') ?? $this->created_at;

        return $since !== null && $since->diffInDays(now()) > $after;
    }
}
