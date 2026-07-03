<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalarySlab extends Model
{
    protected $fillable = [
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
