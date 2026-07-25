<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class Bank extends Model
{
    use Auditable;

    protected $fillable = ['bank_code', 'bank_name', 'bank_short_code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
