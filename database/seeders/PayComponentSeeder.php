<?php

namespace Database\Seeders;

use App\Modules\Payroll\Models\PayComponent;
use Illuminate\Database\Seeder;

/**
 * The parts of pay the system shipped with, described as data.
 *
 * All marked column-backed: each still has its own column on EmployeeSetting and
 * Payslip and the calculation still reads that column. They are rows so the set is
 * complete — a report, a statement or a form can ask what pay is made of instead of
 * carrying its own list, which is how the billing statement came to keep a
 * hand-maintained column map with an "Other" bucket.
 *
 * Account keys are the ones PayrollAccounts already uses, so nothing here invents a
 * second way to find an account.
 */
class PayComponentSeeder extends Seeder
{
    /** code, label, kind, account key, taxable, sort */
    public const SHIPPED = [
        ['basic_wage', 'Basic Salary', 'earning', 'basic_wage', true, 10],
        ['medical_allowance', 'Medical Allowance', 'earning', 'medical_allowance', true, 20],
        ['petrol_allowance', 'Petrol Allowance', 'earning', 'petrol_allowance', true, 30],
        ['device_allowance', 'Device Allowance', 'earning', 'device_allowance', true, 40],
        ['bonus', 'Bonus', 'earning', 'bonus_overtime', true, 50],
        ['extra_work_hours', 'Extra Work', 'earning', 'bonus_overtime', true, 60],

        // Paid with salary but not earned: a reimbursement is the employee's own
        // money coming back, so it is not taxable income.
        ['expense_reimbursement', 'Expense Reimbursement', 'earning', 'expense_reimbursement', false, 70],

        ['withholding_tax', 'Withholding Tax', 'deduction', 'tax_payable', false, 110],
        ['advances', 'Advance Recovery', 'deduction', 'employee_advances', false, 120],
        ['meal_deduction', 'Meal Deduction', 'deduction', 'meal_recovery', false, 130],
        ['esi_health_insurance', 'ESI / Health Insurance', 'deduction', 'esi_payable', false, 140],
    ];

    public function run(): void
    {
        foreach (self::SHIPPED as [$code, $label, $kind, $accountKey, $taxable, $sort]) {
            PayComponent::updateOrCreate(
                ['code' => $code],
                [
                    'label' => $label,
                    'kind' => $kind,
                    'account_key' => $accountKey,
                    'is_taxable' => $taxable,
                    'is_column_backed' => true,
                    'is_active' => true,
                    'sort' => $sort,
                ],
            );
        }

        $this->command?->info('Seeded '.count(self::SHIPPED).' pay components (all column-backed).');
    }
}
