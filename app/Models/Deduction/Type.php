<?php

namespace App\Models\Deduction;

use Illuminate\Database\Eloquent\Model;

class Type extends Model
{
    protected $guarded = ['id'];
    protected $table   = 'deduction_types';
}
