<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;

class AnnualTax extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id', 'fiscal_year_id', 'total_annual_income', 'total_net_income',
        'total_annual_tax', 'paid_tax', 'leftover_tax', 'annual_income_tax',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
