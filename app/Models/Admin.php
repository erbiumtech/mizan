<?php

namespace App\Models;

use App\Models\Payroll\Payroll;

class Admin extends Employee
{
    public function payrolls()
    {
        return $this->hasMany(App\Models\Payroll\Payroll::class, 'user_id');
    }
}
