<?php

namespace App\Models;

use App\Models\User;
use CleaniqueCoders\Profile\Concerns\HasProfile;
use \CleaniqueCoders\Profile\Concerns\Bankable;

class Employee extends User
{
    use HasProfile, Bankable;

    protected $table = 'users';

    public function salary()
    {
        return $this->hasOne(Salary::class, 'user_id');
    }

    public function position()
    {
        return $this->hasOne(Position::class, 'user_id');
    }

    public function payslips()
    {
        return $this->hasMany(\App\Models\Payslip\Payslip::class, 'user_id');
    }
}
