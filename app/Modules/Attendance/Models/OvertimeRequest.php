<?php

namespace App\Modules\Attendance\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime asked for and approved — docs/saas-launch-plan.md §14.4. Approval writes
 * the minutes onto the attendance day, which is where payroll reads them; the request
 * then says whether they reach a payslip, and why not if not.
 */
class OvertimeRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    protected $fillable = ['employee_id', 'worked_on', 'minutes', 'reason', 'status', 'submitted_by', 'decided_by', 'decided_at', 'refusal_reason'];

    protected $casts = ['worked_on' => 'date', 'minutes' => 'integer', 'decided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->submitted_by ??= auth()->id());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * One rule — docs/saas-launch-plan.md §14.4: approved overtime reaches the payslip, or
     * the request says why it did not.
     */
    public function paymentNote(): string
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return $this->status === self::STATUS_REFUSED ? 'Not paid: refused' : 'Not yet decided';
        }

        if (! (bool) setting('payroll.pay_overtime', config('payroll.pay_overtime', false))) {
            return 'Not paid: overtime pay is switched off under Company Settings';
        }

        return 'Paid with the '.$this->worked_on->format('F Y').' payslip';
    }
}
