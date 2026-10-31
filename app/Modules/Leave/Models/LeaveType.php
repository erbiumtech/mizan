<?php

namespace App\Modules\Leave\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of leave the company grants.
 *
 * Reference data: HR edits these rows, and the numbers shipped by the seeder are
 * defaults a company's HR must confirm rather than statutory fact. Leave minima in
 * Pakistan are provincial, and this application already takes that position on tax
 * slabs.
 */
class LeaveType extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    /** Every day of the year's entitlement is credited at the start of the year. */
    public const ACCRUAL_ANNUAL_UPFRONT = 'annual_upfront';

    /** A twelfth of the annual figure per completed month. */
    public const ACCRUAL_MONTHLY = 'monthly_accrual';

    /** A twenty-fourth of the annual figure twice a month, on the 1st and the 16th. */
    public const ACCRUAL_SEMI_MONTHLY = 'semi_monthly_accrual';

    /** Credited on completing twelve months of service — the statutory shape. */
    public const ACCRUAL_ON_COMPLETION = 'on_completion_of_service';

    /**
     * Earned by working a weekly off or a public holiday.
     *
     * Recognised here so the column and the balance formula are ready, but nothing
     * credits it yet: it accrues from approved attendance_days, which are phase 2.
     * A type created with this method today simply never accrues, which is the
     * honest state rather than a silent alternative.
     */
    public const ACCRUAL_COMPENSATORY = 'compensatory';

    /** Not counted down at all — sick leave a company does not ration. */
    public const ACCRUAL_UNLIMITED = 'unlimited';

    /** No entitlement: unpaid leave, which is granted rather than earned. */
    public const ACCRUAL_NONE = 'none';

    protected $fillable = [
        'code', 'label', 'kind', 'is_paid', 'accrual_method', 'days_per_year',
        'max_carry_forward', 'allows_half_day', 'requires_document',
        'min_notice_days', 'is_encashable', 'is_active', 'sort',
        // Eligibility and presentation — docs/saas-launch-plan.md §14.4.
        'eligible_genders', 'min_service_months', 'eligible_employment_types', 'max_consecutive_days', 'document_after_days', 'colour', 'pay_percent',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'days_per_year' => 'decimal:1',
        'max_carry_forward' => 'decimal:1',
        'allows_half_day' => 'boolean',
        'requires_document' => 'boolean',
        'min_notice_days' => 'integer',
        'is_encashable' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'eligible_genders' => 'array',
        'eligible_employment_types' => 'array',
        'min_service_months' => 'integer',
        'max_consecutive_days' => 'integer',
        'document_after_days' => 'integer',
        'pay_percent' => 'decimal:2',
    ];

    protected $attributes = [
        'kind' => 'leave',
        'is_paid' => true,
        'accrual_method' => self::ACCRUAL_ANNUAL_UPFRONT,
        'max_carry_forward' => 0,
        'allows_half_day' => true,
        'requires_document' => false,
        'min_notice_days' => 0,
        'is_encashable' => false,
        'is_active' => true,
        'sort' => 0,
    ];

    public function entitlements(): HasMany
    {
        return $this->hasMany(LeaveEntitlement::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this type is counted against a balance at all.
     *
     * `unlimited` and `none` are not: the first is deliberately not rationed, and
     * the second is unpaid leave, which is granted rather than earned. Asking for
     * either does not need an entitlement to exist, and refusing the request
     * because none does would be wrong.
     */
    /**
     * Why this person may not take this leave, or null — docs/saas-launch-plan.md §14.4.
     * Gender, service, employment type and a run of days; the document rule is a reminder
     * on the form rather than a refusal here.
     */
    public function eligibilityProblem(\App\Modules\Employees\Models\Employee $employee, float $days = 0, ?\Illuminate\Support\Carbon $on = null): ?string
    {
        $genders = array_map('strtolower', (array) ($this->eligible_genders ?? []));

        if ($genders !== [] && ! in_array(strtolower((string) $employee->gender), $genders, true)) {
            return "{$this->label} is for ".implode(' and ', array_map('ucfirst', $genders)).' employees.';
        }

        if ($this->min_service_months !== null) {
            $served = $employee->date_of_joining ? (int) $employee->date_of_joining->diffInMonths($on ?? now()) : 0;

            if ($served < $this->min_service_months) {
                return "{$this->label} needs {$this->min_service_months} months of service; {$served} so far.";
            }
        }

        $types = (array) ($this->eligible_employment_types ?? []);

        if ($types !== [] && ! in_array((string) $employee->employment_type, $types, true)) {
            return "{$this->label} is for ".implode(', ', $types).' staff.';
        }

        if ($this->max_consecutive_days !== null && $days > $this->max_consecutive_days) {
            return "{$this->label} allows at most {$this->max_consecutive_days} consecutive days.";
        }

        return null;
    }

    public function isCounted(): bool
    {
        return ! in_array($this->accrual_method, [self::ACCRUAL_UNLIMITED, self::ACCRUAL_NONE], true);
    }

    /** Whether the year-end reset may carry anything of this type forward. */
    public function carriesForward(): bool
    {
        return (float) $this->max_carry_forward > 0;
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['code' => self::suggestCopyOf('code')];
    }
}
