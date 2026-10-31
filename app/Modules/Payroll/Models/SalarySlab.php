<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;

class SalarySlab extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

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
