<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

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
