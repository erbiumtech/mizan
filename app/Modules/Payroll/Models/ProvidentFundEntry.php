<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One movement on an employee's provident fund — docs/saas-launch-plan.md §14.1.
 * Append-only: a mistake is an adjustment entry, never an edit.
 */
class ProvidentFundEntry extends Model
{
    public const UPDATED_AT = null;

    public const KIND_OPENING = 'opening';

    public const KIND_CONTRIBUTION = 'contribution';

    public const KIND_PROFIT = 'profit';

    public const KIND_WITHDRAWAL = 'withdrawal';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KINDS = [
        self::KIND_OPENING => 'Opening balance',
        self::KIND_CONTRIBUTION => 'Monthly contribution',
        self::KIND_PROFIT => 'Profit credited',
        self::KIND_WITHDRAWAL => 'Withdrawal',
        self::KIND_ADJUSTMENT => 'Adjustment',
    ];

    protected $fillable = ['employee_id', 'entry_date', 'kind', 'employee_amount', 'employer_amount', 'reference', 'payslip_id', 'recorded_by'];

    protected $casts = ['entry_date' => 'date', 'employee_amount' => 'decimal:2', 'employer_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->recorded_by ??= auth()->id();

            if (! isset(self::KINDS[$entry->kind])) {
                throw new InvalidArgumentException("\"{$entry->kind}\" is not a kind of provident fund entry.");
            }
        });

        static::updating(fn () => throw new InvalidArgumentException('A provident fund entry is never edited; record an adjustment.'));
        static::deleting(fn () => throw new InvalidArgumentException('A provident fund entry is never deleted; record an adjustment.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** Signed: a withdrawal takes from the fund, everything else adds to it. */
    public function net(): float
    {
        $sum = (float) $this->employee_amount + (float) $this->employer_amount;

        return $this->kind === self::KIND_WITHDRAWAL ? -abs($sum) : $sum;
    }
}
