<?php

namespace App\Services;

use App\Models\EmployeeSetting;
use App\Models\Payslip;
use App\Models\SalarySlab;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\Log;


class PayslipService
{
    /**
     * Calculate payslip data based on employee settings for a specific month and fiscal year.
     */
  public function calculateByParams(
    $employeeId, $month, $fiscalYearId, $bonus = 0, $extraWorkHours = 0,
    $deviceAllowance = 0, $petrolAllowance = 0, $advances = 0, $mealDeduction = 0, $esiInsurance = 0
) {
    // 1. Setting fetch from database
    $setting = EmployeeSetting::where('employee_id', $employeeId)
        ->where('month', $month)
        ->where('fiscal_year_id', $fiscalYearId)
        ->first();

    if (!$setting) {
        return array_fill_keys(['basic_wage', 'medical_allowance', 'device_allowance', 'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance', 'bonus', 'extra_work_hours', 'total_earnings', 'withholding_tax', 'total_deductions', 'net_salary'], 0);
    }

    $data = [
        'basic_wage'           => (float) $setting->basic_wage,
        'medical_allowance'    => (float) $setting->medical_allowance,
        'device_allowance'     => ((float)$deviceAllowance > 0) ? (float)$deviceAllowance : (float)$setting->device_allowance,
        'petrol_allowance'     => ((float)$petrolAllowance > 0) ? (float)$petrolAllowance : (float)$setting->petrol_allowance,
        'advances'             => ((float)$advances > 0) ? (float)$advances : (float)$setting->advances,
        'meal_deduction'       => ((float)$mealDeduction > 0) ? (float)$mealDeduction : (float)$setting->meal_deduction,
        'esi_health_insurance' => ((float)$esiInsurance > 0) ? (float)$esiInsurance : (float)$setting->esi_health_insurance,
        'bonus'                => ((float)$bonus > 0) ? (float)$bonus : (float)($setting->bonus ?? 0),
        'extra_work_hours'     => ((float)$extraWorkHours > 0) ? (float)$extraWorkHours : (float)($setting->extra_work_hours ?? 0),
    ];

    // 3. Earnings Calculate karein (Basic + Allowances + Bonus + Extra Hours)
    $totalEarningsBase = $data['basic_wage'] + $data['petrol_allowance'] + $data['device_allowance'] + $data['bonus'] + $data['extra_work_hours'];
    $data['total_earnings'] = $totalEarningsBase + $data['medical_allowance'];

    // 4. Tax Calculate karein (Medical allowance ka 10% tax-free rule)
    $medicalLimit = $data['total_earnings'] * 0.10;
    $taxableMedical = max(0, $data['medical_allowance'] - $medicalLimit);
    $annualTaxable = ($totalEarningsBase + $taxableMedical) * 12;

    $slab = SalarySlab::where('fiscal_year_id', $fiscalYearId)
        ->where('min_amount', '<=', $annualTaxable)
        ->where(function ($q) use ($annualTaxable) {
            $q->where('max_amount', '>=', $annualTaxable)->orWhereNull('max_amount');
        })->first();

    $tax = $slab ? ($slab->fixed_tax + (($annualTaxable - $slab->min_amount) * $slab->percentage / 100)) / 12 : 0;
    $data['withholding_tax'] = round($tax, 2);

    // 5. Total Deductions (Tax + Advances + Meal + ESI)
    $data['total_deductions'] = round($data['withholding_tax'] + $data['advances'] + $data['meal_deduction'] + $data['esi_health_insurance'], 2);

    // 6. Net Salary (Earnings - Deductions)
    $data['net_salary'] = round($data['total_earnings'] - $data['total_deductions'], 2);

    return $data;
}
    public function generatePdf(Payslip $payslip): string
    {
        $payslip->load('employee.user');
        $fileName = "payslips/payslip-{$payslip->id}.pdf";
        $fullPath = storage_path('app/public/'.$fileName);

        if (! file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        Pdf::view('pdfs.payslip', ['data' => $payslip])
            ->format('a4')
            ->margins(0, 0, 0, 0)
            ->save($fullPath);

        return $fileName;
    }
}
