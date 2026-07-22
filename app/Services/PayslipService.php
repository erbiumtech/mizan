<?php

namespace App\Services;

use App\Models\EmployeeSetting;
use App\Models\FiscalYear;
use App\Models\Payslip;
use Spatie\LaravelPdf\Facades\Pdf;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PayslipService
{
    public function __construct(private TaxCalculatorService $taxCalculator)
    {
    }

    /**
     * Calculate payslip data based on employee settings for a specific month and fiscal year using date ranges.
     */
    public function calculateByParams(
        $employeeId, $month, $fiscalYearId, $bonus = 0, $extraWorkHours = 0,
        $deviceAllowance = 0, $petrolAllowance = 0, $advances = 0, $mealDeduction = 0, $esiInsurance = 0 )
        {
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
            'device_allowance'     => ($deviceAllowance !== null && $deviceAllowance !== '') ? (float)$deviceAllowance : (float)$setting->device_allowance,
            'petrol_allowance'     => ($petrolAllowance !== null && $petrolAllowance !== '') ? (float)$petrolAllowance : (float)$setting->petrol_allowance,
            'advances'             => ($advances !== null && $advances !== '') ? (float)$advances : (float)$setting->advances,
            'meal_deduction'       => ($mealDeduction !== null && $mealDeduction !== '') ? (float)$mealDeduction : (float)$setting->meal_deduction,
            'esi_health_insurance' => ($esiInsurance !== null && $esiInsurance !== '') ? (float)$esiInsurance : (float)$setting->esi_health_insurance,
            'bonus'                => ($bonus !== null && $bonus !== '') ? (float)$bonus : (float)($setting->bonus ?? 0),
            'extra_work_hours'     => ($extraWorkHours !== null && $extraWorkHours !== '') ? (float)$extraWorkHours : (float)($setting->extra_work_hours ?? 0),
        ];

        // 3. Current Month Total Earnings Base
        $totalEarningsBase = $data['basic_wage'] + $data['petrol_allowance'] + $data['device_allowance'] + $data['bonus'] + $data['extra_work_hours'];
        $data['total_earnings'] = $totalEarningsBase + $data['medical_allowance'];

        // 1. Pehle se bani hui payslips ki actual earnings ka sum (Pechle guzar chuke mahino ka actual data)
        $previousEarningsSum = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->sum('total_earnings');

        $completedMonthsCount = Payslip::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('month', '!=', $month)
            ->count();

        // Baqaya kitne mahine rehte hain saal mein
        $remainingMonths = max(0, 12 - ($completedMonthsCount + 1));

        // 2. Projection mein sirf Basic Wage ko remaining months se multiply karein (Allowances ko nahi!)
        $projectedRemainingBasic = $data['basic_wage'] * $remainingMonths;

        // Total Annual Taxable Income Before Medical = (Pechli actual earnings) + (Current month ki total earnings) + (Aage ke mahino ki sirf basic wage)
        $annualEarningsBeforeMedical = $previousEarningsSum + $totalEarningsBase + $projectedRemainingBasic;

        $annualMedical = $data['medical_allowance'] * 12;

        // Medical limit based on 10% of total annual earnings
        $medicalLimit = ($annualEarningsBeforeMedical + $annualMedical) * 0.10;
        $taxableMedicalAnnual = max(0, $annualMedical - $medicalLimit);

        $annualTaxableIncome = $annualEarningsBeforeMedical + $taxableMedicalAnnual;

        // Annual tax nikal kar monthly (`withholding_tax`) mein convert karna
        $totalAnnualTax = $this->taxCalculator->annualTax($annualTaxableIncome, $fiscalYearId);
        $data['withholding_tax'] = round($totalAnnualTax / 12, 2);

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
            ->withBrowsershot(fn (\Spatie\Browsershot\Browsershot $b) => $b->setNodeBinary(config('services.node.binary'))->setNpmBinary(config('services.node.npm')))
            ->margins(0, 0, 0, 0)
            ->save($fullPath);

        return $fileName;
    }
}
