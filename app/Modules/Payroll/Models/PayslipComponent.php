<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a payslip actually paid of one component.
 *
 * A different fact from what the employee was due: a package corrected in September
 * must not change what August paid, which is exactly why this is recorded rather than
 * derived from the settings at read time.
 */
class PayslipComponent extends Model
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
