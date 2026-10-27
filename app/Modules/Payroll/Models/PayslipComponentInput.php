<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's typed override for one component of one payslip — docs/payslip-component-input-plan.md Phase 2.
 *
 * The input, read by the calculation before it computes. Its sibling PayslipComponent is the output, written
 * by the recorder after. Keeping them in separate tables is what stops the feedback loop the plan names: a
 * row here is never overwritten by what the calculation produced from it.
 */
class PayslipComponentInput extends Model
{
    protected $fillable = ['payslip_id', 'pay_component_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
