<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A prospect: somebody who is not yet a customer.
 *
 * Leads are CRM's; customers stay Invoicing's. The reasoning is in the migration and
 * in docs/crms-plan.md §1 — the short version is that a prospect is not a contact you
 * can invoice, and `crm` has to be sellable to a company that owns neither Invoicing
 * nor Accounting.
 *
 * Both cross-module relations here are **guarded, not required**. `employees` gives
 * the owner and the access scoping; `invoicing` gives conversion. Neither is declared
 * in config/modules.php, both are recorded in ModuleBoundaryTest::KNOWN_COUPLINGS, and
 * every surface that offers them checks `modules()->enabled(...)` first — the same
 * shape as Invoicing → Projects.
 */
class Lead extends Model
{
    use Auditable;
    use HasCustomFields;

    /** Captured, nobody has touched it yet. */
    public const STATUS_NEW = 'new';

    /** Somebody is working it. */
    public const STATUS_WORKING = 'working';

    /** Worth pursuing — the state a deal is opened from, in phase 2. */
    public const STATUS_QUALIFIED = 'qualified';

    /** Became a Contact. Terminal, and the row stays readable as the origin. */
    public const STATUS_CONVERTED = 'converted';

    /** Terminal the other way. */
    public const STATUS_LOST = 'lost';

    public const RATING_HOT = 'hot';

    public const RATING_WARM = 'warm';

    public const RATING_COLD = 'cold';

    protected $fillable = [
        'company_name', 'person_name', 'title', 'email', 'phone', 'whatsapp', 'city',
        'lead_source_id', 'owner_employee_id', 'status', 'rating', 'estimated_value',
        'currency_code', 'notes', 'converted_contact_id', 'converted_at',
        'lost_reason', 'lost_at', 'created_by',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        'converted_at' => 'datetime',
        'lost_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
    ];

    /**
     * A lead has to be somebody.
     *
     * Asserted on the model rather than in the form, because the lead-capture endpoint
     * of phase 3.5 and the spreadsheet import of §13 are both unattended writers, and
     * a row with neither a company nor a person is a lead nobody can ever follow up —
     * exactly the kind of record an open endpoint fills a table with.
     *
     * The same shape ContactPerson uses to assert one primary per contact: an
     * invariant that matters is enforced where every writer passes, not hoped for at
     * each one.
     */
    protected static function booted(): void
    {
        static::saving(function (self $lead): void {
            if (trim((string) $lead->company_name) === '' && trim((string) $lead->person_name) === '') {
                throw new InvalidArgumentException(
                    'A lead needs a company or a person — a row with neither is one nobody can follow up.'
                );
            }
        });

        static::creating(function (self $lead): void {
            $lead->created_by ??= auth()->id();
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** Guarded: null, and never offered, when `employees` is unlicensed. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    /** Guarded: what this lead became, when `invoicing` is licensed. */
    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    /** Soft reference to the landlord users table, and the owner fallback. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still worth somebody's time: not converted, not lost. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_CONVERTED, self::STATUS_LOST]);
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONVERTED);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_CONVERTED, self::STATUS_LOST], true);
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    /** What to call this lead in a list, given either half of its identity may be blank. */
    public function getDisplayLabelAttribute(): string
    {
        $company = trim((string) $this->company_name);
        $person = trim((string) $this->person_name);

        if ($company !== '' && $person !== '') {
            return "{$company} — {$person}";
        }

        return $company !== '' ? $company : $person;
    }
}
