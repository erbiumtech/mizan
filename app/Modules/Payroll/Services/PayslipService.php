<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Modules\Payroll\Support\PayrollMonth;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PayslipService
{
    public function __construct(private TaxCalculatorService $taxCalculator)
    {
    }

    /**
     * This month's advance instalment, or 0.0 when the employee has none.
     *
     * Resolved through the container so Payroll does not hard-depend on the
     * Advances module: with Advances not installed or switched off there is no
     * ledger to read, and payroll carries on with the settings figure.
     */
    protected function advanceInstalmentFor($employeeId, ?int $excludingPayslipId = null): float
    {
        if (! modules()->enabled('advances')) {
            return 0.0;
        }

        return app(\App\Modules\Advances\Services\AdvanceService::class)
            ->instalmentFor((int) $employeeId, $excludingPayslipId);
    }

    /**
     * Calculate payslip data based on employee settings for a specific month and fiscal year using date ranges.
     */
    public function calculateByParams(
        $employeeId, $month, $fiscalYearId, $bonus = null, $extraWorkHours = null,
        $deviceAllowance = null, $petrolAllowance = null, $advances = null, $mealDeduction = null, $esiInsurance = null, $expenseReimbursement = null,
        ?int $payslipId = null
    ) {
        $fiscalYear = FiscalYear::find($fiscalYearId);

        $targetDate = PayrollMonth::firstDay($month, $fiscalYear)->toDateString();

        $setting = EmployeeSetting::getActiveSettingForDate($employeeId, $targetDate, $fiscalYearId);

        if (!$setting) {
            return array_fill_keys(['basic_wage', 'medical_allowance', 'device_allowance', 'petrol_allowance', 'advances', 'meal_deduction', 'esi_health_insurance', 'bonus', 'extra_work_hours', 'expense_reimbursement', 'total_earnings', 'withholding_tax', 'total_deductions', 'net_salary'], 0);
        }

        $data = [
           'basic_wage'           => (float) $setting->basic_wage,
            'medical_allowance'    => (float) $setting->medical_allowance,
            'device_allowance'     => ((float)$deviceAllowance > 0) ? (float)$deviceAllowance : (float)$setting->device_allowance,
            'petrol_allowance'     => ((float)$petrolAllowance > 0) ? (float)$petrolAllowance : (float)$setting->petrol_allowance,
            // From the advance ledger when the employee has one, so the figure
            // deducted and the balance still owed are the same fact. An explicit
            // amount passed in still wins — a payroll clerk overriding one month
            // is a legitimate correction — and an employee with no advance falls
            // back to their settings exactly as before.
            'advances'             => ((float)$advances > 0)
                ? (float)$advances
                : ($this->advanceInstalmentFor($employeeId, $payslipId) ?: (float)$setting->advances),
            'meal_deduction'       => ((float)$mealDeduction > 0) ? (float)$mealDeduction : (float)$setting->meal_deduction,
            'esi_health_insurance' => ((float)$esiInsurance > 0) ? (float)$esiInsurance : (float)$setting->esi_health_insurance,
            'bonus'                => ((float)$bonus > 0) ? (float)$bonus : (float)($setting->bonus ?? 0),
            'extra_work_hours'     => ((float)$extraWorkHours > 0) ? (float)$extraWorkHours : (float)($setting->extra_work_hours ?? 0),
            'expense_reimbursement' => (float) ($expenseReimbursement ?? 0),
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

        // Net Salary (Earnings + Expense Reimbursement - Deductions)
        $data['net_salary'] = round($data['total_earnings'] + $data['expense_reimbursement'] - $data['total_deductions'], 2);

        return $data;
    }

    /**
     * The payslip as a PDF, rendered from the payslip as it stands now.
     *
     * Never from a file. A payslip is corrected after it is first printed — an
     * allowance fixed, attendance entered, an advance instalment picked up — and
     * every previous version of this served whatever was already on disk, so the
     * copy an employee downloaded went on showing figures the system had since
     * changed. There is nothing to invalidate and nothing to go stale because
     * nothing is kept.
     */
    public function renderPdf(Payslip $payslip): PdfDocument
    {
        $payslip->load('employee.user', 'fiscalYear');

        return Pdf::view('pdfs.payslip', ['data' => $payslip])
            ->format('a4')
            ->margins(0, 0, 0, 0)
            ->name($this->pdfFilename($payslip));
    }

    /**
     * Names the employee, the month and the fiscal year — all three, because the
     * month was missing from the name the API used (it read a `pay_period`
     * attribute that does not exist), which collapsed every month of a fiscal
     * year onto one file per employee.
     */
    public function pdfFilename(Payslip $payslip): string
    {
        $parts = [
            $payslip->employee?->employee_id ?: 'employee-'.$payslip->employee_id,
            $payslip->month,
            $payslip->fiscalYear?->name,
        ];

        return str_replace(
            [' ', '/', '\\'],
            '-',
            implode('-', array_filter($parts)),
        ).'.pdf';
    }
}
