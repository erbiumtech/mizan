<?php

namespace App\Services;

use App\Models\Payslip;
use App\Models\EmployeeSetting;
use App\Models\SalarySlab;
use Spatie\LaravelPdf\Facades\Pdf;

class PayslipService
{
    public function calculatePayslipData($employeeId, $bonus = 0, $extraWorkHours = 0)
    {
        $setting = EmployeeSetting::where('employee_id', $employeeId)->first();
        if (!$setting) return null;

        $finalBonus = ((float)$bonus > 0) ? (float)$bonus : (float)($setting->bonus ?? 0);
        $finalExtra = ((float)$extraWorkHours > 0) ? (float)$extraWorkHours : (float)($setting->extra_work_hours ?? 0);

        $data = [
            'basic_wage' => (float)$setting->basic_wage,
            'medical_allowance' => (float)$setting->medical_allowance,
            'device_allowance' => (float)$setting->device_allowance,
            'petrol_allowance' => (float)$setting->petrol_allowance,
            'advances' => (float)$setting->advances,
            'meal_deduction' => (float)$setting->meal_deduction,
            'esi_health_insurance' => (float)$setting->esi_health_insurance,
            'bonus' => $finalBonus,
            'extra_work_hours' => $finalExtra,
        ];

        $medicalExemptLimit = $data['basic_wage'] * 0.10;
        $taxableMedical = max(0, $data['medical_allowance'] - $medicalExemptLimit);

        $monthlyTaxable = $data['basic_wage'] + $data['petrol_allowance'] + $data['device_allowance'] + $finalBonus + $finalExtra + $taxableMedical;
        $annualTaxable = $monthlyTaxable * 12;

        $slab = SalarySlab::where('min_amount', '<=', $annualTaxable)
                    ->where(function ($q) use ($annualTaxable) {
                        $q->where('max_amount', '>=', $annualTaxable)->orWhereNull('max_amount');
                    })->first();

        $tax = $slab ? ($slab->fixed_tax + (($annualTaxable - $slab->min_amount) * $slab->percentage / 100)) / 12 : 0;

        $data['withholding_tax'] = round($tax, 2);
        $data['total_earnings'] = $monthlyTaxable - $taxableMedical + $data['medical_allowance'];
        $data['total_deductions'] = $data['withholding_tax'] + $data['advances'] + $data['meal_deduction'] + $data['esi_health_insurance'];
        $data['net_salary'] = round($data['total_earnings'] - $data['total_deductions'], 2);

        return $data;
    }

    public function generatePdf(Payslip $payslip): string
    {
        $payslip->load('employee.user');
        $fileName = "payslips/payslip-{$payslip->id}.pdf";
        $fullPath = storage_path('app/public/' . $fileName);
        if (!file_exists(dirname($fullPath))) mkdir(dirname($fullPath), 0755, true);
        Pdf::view('pdfs.payslip', ['data' => $payslip])->format('a4')->margins(0, 0, 0, 0)->save($fullPath);
        return $fileName;
    }
}
