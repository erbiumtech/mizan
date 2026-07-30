<?php

namespace App\Modules\Payroll\Services;

use App\Models\FiscalYear;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Support\Pdf\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PayslipService
{
    public function __construct(private TaxCalculatorService $taxCalculator)
    {
    }

    /**
     * Calculate payslip data based on employee settings for a specific month and fiscal year using date ranges.
     */
    public function calculateByParams(
        $employeeId, $month, $fiscalYearId, $bonus = null, $extraWorkHours = null,
        $deviceAllowance = null, $petrolAllowance = null, $advances = null, $mealDeduction = null, $esiInsurance = null
    ) {
        $fiscalYear = FiscalYear::find($fiscalYearId);

        $startYear = $fiscalYear ? Carbon::parse($fiscalYear->start_date)->year : 2026;

        $tempDate = Carbon::parse("{$month} 1, {$startYear}");
        $year = ($tempDate->month <= 6 && $fiscalYear && Carbon::parse($fiscalYear->end_date)->year > $startYear)
            ? Carbon::parse($fiscalYear->end_date)->year
            : $startYear;

        $targetDate = Carbon::parse("{$month} 1, {$year}")->toDateString();

        $setting = EmployeeSetting::getActiveSettingForDate($employeeId, $targetDate, $fiscalYearId);

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

        // Current Month Total Earnings Base (Form values ke sath)
        $totalEarningsBase = $data['basic_wage'] + $data['petrol_allowance'] + $data['device_allowance'] + $data['bonus'] + $data['extra_work_hours'];
        $data['total_earnings'] = $totalEarningsBase + $data['medical_allowance'];

        $previousEarningsSum = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->sum('total_earnings');

        $completedMonths = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->pluck('month')
            ->toArray();

        $allSettings = EmployeeSetting::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        $annualTotalEarnings = $previousEarningsSum;


        if ($allSettings->isNotEmpty()) {
            foreach ($allSettings as $empSetting) {
                $sMonthlyTotal = (float)$empSetting->basic_wage
                                 + (float)$empSetting->medical_allowance
                                 + (float)$empSetting->petrol_allowance
                                 + (float)$empSetting->device_allowance
                                 + (float)($empSetting->bonus ?? 0)
                                 + (float)($empSetting->extra_work_hours ?? 0);

                if ($empSetting->id == $setting->id) {
                    $sMonthlyTotal = $data['total_earnings'];
                }

                $sStart = Carbon::parse($empSetting->start_date);
                $sEnd = Carbon::parse($empSetting->end_date);

                $currentPeriodCursor = $sStart->copy();
                while ($currentPeriodCursor <= $sEnd) {
                    $mName = $currentPeriodCursor->format('F'); //  July, August

                    if ($mName === $month || !in_array($mName, $completedMonths)) {
                        $annualTotalEarnings += ($mName === $month) ? $data['total_earnings'] : $sMonthlyTotal;
                    }

                    $currentPeriodCursor->addMonth();
                }
            }
        } else {
            $completedMonthsCount = count($completedMonths);
            $remainingMonths = max(0, 12 - ($completedMonthsCount + 1));
            $annualTotalEarnings = $previousEarningsSum + $data['total_earnings'] + ($data['total_earnings'] * $remainingMonths);
        }

        Log::debug('Corrected Annual Total Earnings: ' . $annualTotalEarnings);

        // Instructor & FBR Rule: Poori Annual Total Earnings ka 10% medical cut
        $medicalExemption = $annualTotalEarnings * 0.10;
        Log::debug('10% Medical Exemption Cut: ' . $medicalExemption);

        // Annual Taxable Income = Total Earnings - 10% Exemption Cut
        $annualTaxableIncome = max(0, $annualTotalEarnings - $medicalExemption);
        Log::debug('Final Annual Taxable Income: ' . $annualTaxableIncome);

        // Annual tax nikalna
        $totalAnnualTax = $this->taxCalculator->annualTax($annualTaxableIncome, $fiscalYearId);

        // Ab tak pichle mahino mein kitna tax pay ho chuka hai (Current month ko chor kar)
        $previousTaxPaid = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->sum('withholding_tax');

        // Total saal mein kitne mahine complete ho chuke hain
        $completedMonthsCount = count($completedMonths);
        
        $remainingMonthsForTax = max(1, 12 - $completedMonthsCount);

        // Leftover tax jo abhi tak pay nahi hua
        $leftoverAnnualTax = max(0, $totalAnnualTax - $previousTaxPaid);

        // Monthly withholding tax = Leftover tax ko baqi rehnay walay mahino par divide karna
        $data['withholding_tax'] = round($leftoverAnnualTax / $remainingMonthsForTax, 2);
        Log::debug('Withholding Tax for this month: ' . $data['withholding_tax']);

        // Total Deductions (Tax + Advances + Meal + ESI)
        $data['total_deductions'] = round($data['withholding_tax'] + $data['advances'] + $data['meal_deduction'] + $data['esi_health_insurance'], 2);

        // Net Salary (Earnings - Deductions)
        $data['net_salary'] = round($data['total_earnings'] - $data['total_deductions'], 2);

        return $data;
    }

    public function generatePdf(Payslip $payslip): string
    {
        $payslip->load('employee.user');
        $fileName = "payslips/payslip-{$payslip->id}.pdf";

        // Route through the `public` disk so per-tenant storage isolation
        // (SwitchTenantFilesystemTask) applies to the absolute save path.
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $disk->makeDirectory(dirname($fileName));
        $fullPath = $disk->path($fileName);

        Pdf::view('pdfs.payslip', ['data' => $payslip])
            ->format('a4')
            ->margins(0, 0, 0, 0)
            ->save($fullPath);

        return $fileName;
    }
}
