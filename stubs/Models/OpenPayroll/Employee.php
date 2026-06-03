<?php

namespace App\Models\OpenPayroll;

use App\Models\Employee as normalEmployee;
use CleaniqueCoders\Profile\Traits\HasProfile;
use CleaniqueCoders\Profile\Traits\Morphs\Bankable;

class Employee extends normalEmployee
{
    use HasProfile, Bankable;

    protected $table = 'employees';

    public function salary()
    {
        return $this->hasOne(Salary::class, 'user_id', 'user_id');
    }

    public function position()
    {
        return $this->hasOne(Position::class, 'user_id', 'user_id');
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class, 'user_id', 'user_id');
    }
}
