<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\EmployeeSetting;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an employee is due of one component, for one version of their package.
 *
 * Hangs off EmployeeSetting rather than off the employee, so it inherits the date
 * ranges payroll already versions packages by: a raise in March is a new setting,
 * and its components go with it.
 */
class EmployeeSettingComponent extends Model
{
    protected $fillable = ['employee_setting_id', 'pay_component_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(EmployeeSetting::class, 'employee_setting_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
