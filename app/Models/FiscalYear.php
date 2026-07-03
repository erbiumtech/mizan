<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = ['name', 'is_active'];

    public function salarySlabs() {
        return $this->hasMany(SalarySlab::class);
    }
}
