<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Core\Models\FiscalYear;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class SalarySlab extends Model
{
    use Auditable;

    protected $fillable = [
        'fiscal_year_id',
        'min_amount',
        'max_amount',
        'fixed_tax',
        'percentage',
    ];

    // app/Models/SalarySlab.php
    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
