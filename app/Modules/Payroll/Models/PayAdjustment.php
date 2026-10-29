<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Services\PayAdjustments;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A dated one-off line for one employee in one month — docs/saas-launch-plan.md §14.1.
 * A bonus, an extra deduction, a recovered advance. Written to the payslip's component
 * input store when the month is raised, or at once when the payslip already exists.
 */
class PayAdjustment extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'fiscal_year_id', 'month', 'pay_component_id', 'amount', 'reason', 'payslip_id', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $adjustment->created_by ??= auth()->id();

            // A signed-off month does not move, so an adjustment for it is refused rather than kept
            // somewhere it can never apply.
            if ($adjustment->isFrozen()) {
                throw new InvalidArgumentException("{$adjustment->month} has been signed off. Reopen the run to adjust it.");
            }
        });

        // Reaches the payslip as soon as it is written, never by somebody remembering to recalculate.
        static::saved(fn (self $adjustment) => app(PayAdjustments::class)->refresh($adjustment->employee_id, $adjustment->month, $adjustment->fiscal_year_id));
        static::deleted(fn (self $adjustment) => app(PayAdjustments::class)->refresh($adjustment->employee_id, $adjustment->month, $adjustment->fiscal_year_id));
    }

    /** Whether the month this adjusts has been signed off — by its payslip's run, or the month's run when none is linked yet. */
    public function isFrozen(): bool
    {
        $run = $this->payslip?->payrollRun
            ?? PayrollRun::query()->where('month', $this->month)->where('fiscal_year_id', $this->fiscal_year_id)->first();

        return $run?->isLocked() ?? false;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
