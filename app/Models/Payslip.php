<?php

namespace App\Models;

use App\Services\PayslipService;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $fillable = [
        'employee_id', 'pay_period', 'total_working_days', 'paid_days', 'lop_days',
        'leaves_taken', 'basic_wage', 'medical_allowance', 'device_allowance',
        'petrol_allowance', 'extra_work_hours', 'bonus', 'withholding_tax',
        'advances', 'meal_deduction', 'esi_health_insurance', 'total_earnings',
        'total_deductions', 'net_salary', 'pdf_path',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    protected static function booted()
    {
        static::creating(function ($payslip) {
            $service = new PayslipService;

            $calculatedData = $service->calculatePayslipData(
                $payslip->employee_id,
                $payslip->bonus,
                $payslip->extra_work_hours
            );

            if ($calculatedData) {
                $payslip->basic_wage = $calculatedData['basic_wage'];
                $payslip->medical_allowance = $calculatedData['medical_allowance'];
                $payslip->device_allowance = $calculatedData['device_allowance'];
                $payslip->petrol_allowance = $calculatedData['petrol_allowance'];
                $payslip->bonus = $calculatedData['bonus'];
                $payslip->extra_work_hours = $calculatedData['extra_work_hours'];
                $payslip->withholding_tax = $calculatedData['withholding_tax'];
                $payslip->advances = $calculatedData['advances'];
                $payslip->meal_deduction = $calculatedData['meal_deduction'];
                $payslip->esi_health_insurance = $calculatedData['esi_health_insurance'];
                $payslip->total_earnings = $calculatedData['total_earnings'];
                $payslip->total_deductions = $calculatedData['total_deductions'];
                $payslip->net_salary = $calculatedData['net_salary'];
            }
        });
    }
}
