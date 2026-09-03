<?php

namespace App\Modules\Lifecycle\Models;

use App\Models\Concerns\StoresPlainDates;
use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody is owed, or owes, on leaving.
 *
 * **A proposal, not a posting.** Every figure here is gathered from somewhere the
 * system already knows — the advance ledger, encashable leave, gratuity, unreturned
 * kit — and produced for a human to approve. Paying it goes through the existing
 * payslip or payment path, which posts to the ledger correctly. A second money path
 * writing its own journal entries is how a ledger stops reconciling.
 *
 * That is why `payslip_id` and `payment_id` are here and nullable: this table records
 * which existing path settled it, and neither of those paths knows this table exists.
 */
class FinalSettlement extends Model
{
    use Auditable;
    use StoresPlainDates;

    protected array $plainDates = ['left_on'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'employee_id', 'left_on', 'notice_recovery', 'leave_encashment_days',
        'leave_encashment_amount', 'gratuity_amount', 'outstanding_advance',
        'unreturned_asset_value', 'other_deductions', 'net_amount', 'status',
        'approved_by', 'approved_at', 'payslip_id', 'payment_id', 'notes',
    ];

    protected $casts = [
        'left_on' => 'date',
        'notice_recovery' => 'decimal:2',
        'leave_encashment_days' => 'decimal:1',
        'leave_encashment_amount' => 'decimal:2',
        'gratuity_amount' => 'decimal:2',
        'outstanding_advance' => 'decimal:2',
        'unreturned_asset_value' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Approved and not yet settled.
     *
     * Deliberately not "anything past draft": `paid` records that a payslip or a payment
     * has been through, and that is the line reopening must never cross.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Everything owed, less everything owed back.
     *
     * Can legitimately be negative: somebody leaving with an unrecovered advance and an
     * unreturned laptop may owe the company. The figure is presented as it falls rather
     * than clamped, because a settlement clamped to zero quietly writes off a debt
     * nobody decided to write off.
     */
    public function computedNet(): float
    {
        return round(
            (float) $this->leave_encashment_amount
            + (float) $this->gratuity_amount
            - (float) $this->notice_recovery
            - (float) $this->outstanding_advance
            - (float) $this->unreturned_asset_value
            - (float) $this->other_deductions,
            2,
        );
    }

    public function isOwedToCompany(): bool
    {
        return $this->computedNet() < 0;
    }
}
