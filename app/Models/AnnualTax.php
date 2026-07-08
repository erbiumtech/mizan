<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnualTax extends Model
{
    protected $fillable = [
        'employee_id', 'fiscal_year_id', 'total_net_income',
        'total_annual_tax', 'paid_tax', 'leftover_tax' , 'annual_income_tax'
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
