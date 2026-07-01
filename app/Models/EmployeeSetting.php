<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSetting extends Model
{
    protected $fillable = [
        'employee_id', 'basic_wage', 'medical_allowance', 'device_allowance',
        'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance',
        'bonus', 'extra_work_hours',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
