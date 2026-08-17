<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Banking\PayrollMonth;
use App\Support\ModuleMap;
use RuntimeException;

/**
 * One draft salary payment per payslip of a month.
 *
 * This was `Accounting\Services\PaymentService::generateSalaryPayments()`, and it is the clearest case of
 * code filed on the wrong side of a boundary: it reads payslips, it decides what a salary is worth, and it
 * knows that a payslip's review state travels onto the payment. All three are payroll's business.
 * Accounting still *triggers* it — the bank payment file raises the month's payables when it is opened —
 * but through `App\Support\PaymentGenerators` rather than by naming this class. See
 * docs/module-packaging-plan.md §8 Group C.
 *
 * Payroll depending on Accounting is the acceptable direction: Payroll already posts its journal entries
 * through Accounting, and the reverse was the cycle.
 */
class SalaryPaymentGenerator
{
    /**
     * One draft salary Payment per payslip of the month (idempotent via
     * the unique payslip_id).
     */
    public function generate(string $month, FiscalYear $fiscalYear): int
    {
        $type = TransactionType::byCode('salary');

        if (! $type) {
            throw new RuntimeException('Salary transaction type not found. Run TransactionTypeSeeder.');
        }

        $defaultAccount = $type->defaultCompanyBankAccount();
        $year = PayrollMonth::yearFor($month, $fiscalYear);
        $created = 0;

        $payslips = Payslip::with('employee')
            ->where('month', $month)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->get();

        foreach ($payslips as $payslip) {
            $payment = Payment::firstOrNew(['payslip_id' => $payslip->id]);

            // Status, batch reference and released_at are deliberately absent
            // here. This runs every time either bank-file page is opened, and an
            // updateOrCreate that included status put every released payment back
            // to draft on the next page view — so "exported" never stuck and a
            // salary already sent to the bank reappeared in the following batch.
            //
            // A released payment is a record of what was actually sent, so its
            // figures are left alone too: restating the amount afterwards would
            // make the row disagree with the file the bank received.
            if (! $payment->isReleased()) {
                $payment->fill([
                    // The stable alias, not the live class: payable_type is one of
                    // the columns holding a class name across the module move.
                    'payable_type' => ModuleMap::alias(Employee::class),
                    'payable_id' => $payslip->employee_id,
                    'transaction_type_id' => $type->id,
                    'company_bank_account_id' => $defaultAccount?->id,
                    'amount' => $payslip->net_salary,
                    'details' => "Salary {$month} {$year}",

                    // The payslip's review state, copied onto the payment at creation.
                    //
                    // The listener on PayslipReviewed keeps this current when somebody reviews a payslip
                    // *later*; this covers the other order, which is the common one — a payslip accepted
                    // before the bank file is opened, so the payment is created already knowing. Without
                    // it every generated payment would start life looking unaccepted and the whole batch
                    // would be held back. See docs/module-packaging-plan.md §8 Group C.
                    'subject_review' => $payslip->employee_review,
                    'subject_review_reason' => $payslip->employee_rejection_reason,
                    'subject_reviewed_at' => $payslip->employee_reviewed_at,
                ]);
            }

            if (! $payment->exists) {
                $payment->status = Payment::STATUS_DRAFT;
                $created++;
            }

            $payment->save();
        }

        return $created;
    }
}
