<?php

namespace App\Models;

use App\Services\PayslipService;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $fillable = [
        'employee_id', 'month', 'fiscal_year_id', 'total_working_days', 'paid_days', 'lop_days',
        'leaves_taken', 'basic_wage', 'medical_allowance', 'device_allowance',
        'petrol_allowance', 'extra_work_hours', 'bonus', 'withholding_tax',
        'advances', 'meal_deduction', 'esi_health_insurance', 'annual_income_tax', 'total_net_income', 'total_earnings',
        'total_deductions', 'net_salary', 'pdf_path',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    protected static function booted()
    {
        $calculate = function ($payslip) {
            $service = new PayslipService;

            $calculatedData = $service->calculateByParams(
                $payslip->employee_id,
                $payslip->month,
                $payslip->fiscal_year_id,
                $payslip->bonus,
                $payslip->extra_work_hours,
                $payslip->device_allowance,
                $payslip->petrol_allowance,
                $payslip->advances,
                $payslip->meal_deduction,
                $payslip->esi_health_insurance
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
        };

        static::creating($calculate);
        static::updating($calculate);

        $syncTax = function ($payslip) {
            self::calculateAndStoreAnnualTax($payslip->employee_id, $payslip->fiscal_year_id);
        };

        static::saved($syncTax);
        static::deleted($syncTax);
    }

    public static function calculateAndStoreAnnualTax($employeeId, $fiscalYearId)
    {
        $payslips = self::where('employee_id', $employeeId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->get();

        if ($payslips->isEmpty()) {
            AnnualTax::where('employee_id', $employeeId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->delete();
            return;
        }

        $totalActualIncomeYearToDate = 0;
        $totalNetSalaryYearToDate = 0;
        $totalPaidTaxYearToDate = 0;
        $totalMedicalYearToDate = 0;
        $monthsCount = $payslips->count();

        foreach ($payslips as $p) {
            $totalActualIncomeYearToDate += (float) ($p->total_earnings ?? 0);
            $totalNetSalaryYearToDate += (float) ($p->net_salary ?? 0);
            $totalPaidTaxYearToDate += (float) ($p->withholding_tax ?? 0);
            $totalMedicalYearToDate += (float) ($p->medical_allowance ?? 0);
        }

        $avgMonthlyIncome = $monthsCount > 0 ? ($totalActualIncomeYearToDate / $monthsCount) : 0;
        $annualProjectedIncome = $avgMonthlyIncome * 12;

        // Net salary projected average
        $avgMonthlyNet = $monthsCount > 0 ? ($totalNetSalaryYearToDate / $monthsCount) : 0;
        $annualProjectedNetIncome = $avgMonthlyNet * 12;

        $avgMonthlyMedical = $monthsCount > 0 ? ($totalMedicalYearToDate / $monthsCount) : 0;
        $annualProjectedMedical = $avgMonthlyMedical * 12;

        // FBR Medical Exemption Rule (Annual) for Taxable calculation
        $annualMedicalLimit = $annualProjectedIncome * 0.10;
        $annualTaxableMedical = max(0, $annualProjectedMedical - $annualMedicalLimit);
        $annualNonMedicalBase = $annualProjectedIncome - $annualProjectedMedical;

        $annualTaxableIncome = $annualNonMedicalBase + $annualTaxableMedical;

        $slab = \App\Models\SalarySlab::where('fiscal_year_id', $fiscalYearId)
            ->where('min_amount', '<=', $annualTaxableIncome)
            ->where(function ($q) use ($annualTaxableIncome) {
                $q->where('max_amount', '>=', $annualTaxableIncome)->orWhereNull('max_amount');
            })->first();

        $annualTotalTax = $slab ? ($slab->fixed_tax + (($annualTaxableIncome - $slab->min_amount) * $slab->percentage / 100)) : 0;
        $leftoverTax = max(0, $annualTotalTax - $totalPaidTaxYearToDate);

        AnnualTax::updateOrCreate(
            [
                'employee_id' => $employeeId,
                'fiscal_year_id' => $fiscalYearId,
            ],
            [
                'total_annual_income' => round($annualProjectedIncome, 2),
                'annual_income_tax'   => round($annualTaxableIncome, 2),
                'total_net_income'    => round($annualProjectedNetIncome, 2),
                'total_annual_tax'    => round($annualTotalTax, 2),
                'paid_tax'            => round($totalPaidTaxYearToDate, 2),
                'leftover_tax'        => round($leftoverTax, 2),
            ]
        );
    }

    public static function syncAnnualTax($employeeId, $fiscalYearId)
    { 
        self::calculateAndStoreAnnualTax($employeeId, $fiscalYearId);
    }
}
