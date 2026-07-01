<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalarySlab extends Model
{
    protected $fillable = [
        'min_amount',
        'max_amount',
        'fixed_tax',
        'percentage'
    ];
}
