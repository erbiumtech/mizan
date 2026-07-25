<?php

namespace App\Models;

use App\Models\TenantModel as Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];
}
