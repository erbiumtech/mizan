<?php

namespace App\Services;

use App\Models\Payslip;
use App\Models\EmployeeSetting;
use App\Models\SalarySlab;
use Spatie\LaravelPdf\Facades\Pdf;
use Carbon\Carbon;

class PayslipService
{
    /**
     * Calculate payslip data based on the selected version/setting and pay period.
     */
    public function calculateByVersion($versionId, $bonus = 0, $extraWorkHours = 0, $payPeriod = null)
    {
        // 1. Fetch Setting record
        $setting = EmployeeSetting::where('version_id', $versionId)->first();
        if (!$setting) return null;

        $finalBonus = ((float)$bonus > 0) ? (float)$bonus : (float)($setting->bonus ?? 0);
        $finalExtra = ((float)$extraWorkHours > 0) ? (float)$extraWorkHours : (float)($setting->extra_work_hours ?? 0);

        // 2. Prepare Base Data
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

        // 3. Calculate Total Earnings (Medical Allowance is also the part of earnings).
        $totalEarningsBase = $data['basic_wage'] + $data['petrol_allowance'] + $data['device_allowance'] + $finalBonus + $finalExtra;
        $data['total_earnings'] = $totalEarningsBase + $data['medical_allowance'];

        // 4. Tax Calculation (Only if Pay Period is selected).
        $tax = 0;
        if ($payPeriod) {
            $date = Carbon::parse($payPeriod);
            // Pakistan Fiscal Year: July 1 to June 30
            $fiscalYearStart = ($date->month >= 7) ? $date->year : ($date->year - 1);

            // Medical Allowance Tax Rule
            $medicalLimit = $data['total_earnings'] * 0.10;
            $taxableMedical = max(0, $data['medical_allowance'] - $medicalLimit);

            $monthlyTaxable = $totalEarningsBase + $taxableMedical;
            $annualTaxable = $monthlyTaxable * 12;

            $slab = SalarySlab::where('fiscal_year_start', $fiscalYearStart)
                              ->where('min_amount', '<=', $annualTaxable)
                              ->where(function ($q) use ($annualTaxable) {
                                  $q->where('max_amount', '>=', $annualTaxable)->orWhereNull('max_amount');
                              })->first();

            $tax = $slab ? ($slab->fixed_tax + (($annualTaxable - $slab->min_amount) * $slab->percentage / 100)) / 12 : 0;
        }

        $data['withholding_tax'] = round($tax, 2);

        // 5. Total Deductions (Tax + Advances + Meal + ESI)
        $data['total_deductions'] = round(
            $data['withholding_tax'] +
            $data['advances'] +
            $data['meal_deduction'] +
            $data['esi_health_insurance'],
        2);

        // 6. Net Salary
        $data['net_salary'] = round($data['total_earnings'] - $data['total_deductions'], 2);

        return $data;
    }

    /**
     * Generate PDF for the given Payslip model.
     */
    public function generatePdf(Payslip $payslip): string
    {
        $payslip->load('employee.user');
        $fileName = "payslips/payslip-{$payslip->id}.pdf";
        $fullPath = storage_path('app/public/' . $fileName);

        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        Pdf::view('pdfs.payslip', ['data' => $payslip])
            ->format('a4')
            ->margins(0, 0, 0, 0)
            ->save($fullPath);

        return $fileName;
    }
}
