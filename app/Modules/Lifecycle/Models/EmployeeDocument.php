<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\Concerns\ExpiresOn;
use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document with an expiry date.
 *
 * The expiry is the point. A visa or a driving licence that lapsed last month is the
 * kind of thing nobody notices until it matters, and the reminder machinery here is the
 * one the project certificate checks already prove out: a daily command, thresholds in
 * config, and notifications **on transitions rather than on every run**.
 */
class EmployeeDocument extends Model
{
    use Auditable;

    // The expiry answers — docs/bexio-gap-plan.md §3.7 lifted them out so contact documents share them.
    use ExpiresOn;
    use StoresPlainDates;

    protected array $plainDates = ['issued_on', 'expires_on'];

    /**
     * An insurance policy is a document that expires — docs/bexio-gap-plan.md Phase 5, item 19. The
     * `number` is the policy number, `issued_on`/`expires_on` its term, and three columns of its own
     * carry what a visa has not: the insurer, the sum insured and the premium.
     */
    public const KIND_INSURANCE = 'insurance';

    /** @var array<int, string> */
    public const KINDS = ['cnic', 'passport', 'visa', 'licence', 'degree', 'contract', self::KIND_INSURANCE, 'other'];

    /** The same kinds, as a company reads them — docs/saas-launch-plan.md §14.8. */
    public const KINDS_LABELLED = [
        'cnic' => 'CNIC',
        'passport' => 'Passport',
        'visa' => 'Visa',
        'licence' => 'Licence',
        'degree' => 'Degree',
        'contract' => 'Contract',
        self::KIND_INSURANCE => 'Insurance',
        'other' => 'Other',
    ];

    protected $fillable = [
        'employee_id', 'kind', 'number', 'issued_on', 'expires_on',
        'insurer', 'cover_amount', 'premium',
        'file_path', 'verified_by', 'verified_at', 'expiry_notified_at_days',
        'rejected_at', 'rejection_reason', 'rejected_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'cover_amount' => 'decimal:2',
        'premium' => 'decimal:2',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expiry_notified_at_days' => 'integer',
    ];

    public function isInsurance(): bool
    {
        return $this->kind === self::KIND_INSURANCE;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    /**
     * Waiting on somebody — docs/saas-launch-plan.md §14.8.
     *
     * Neither accepted nor refused. The queue the compliance screen filters on, and
     * the only state in which a document is still the company's to answer.
     */
    public function isAwaitingReview(): bool
    {
        return $this->verified_at === null && $this->rejected_at === null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
