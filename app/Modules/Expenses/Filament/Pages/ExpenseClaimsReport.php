<?php

namespace App\Modules\Expenses\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Claims by state, per person, and what the company owes.
 *
 * `docs/reports-expansion-plan.md` Phase 2.8: "by status, employee and period; reimbursed through payroll
 * versus pending, where the pending total is an accrued liability."
 *
 * **The liability is why it exists.** A claim approved and not yet paid is money owed to an employee, and
 * nothing here posts it — it reaches the ledger only when a payslip reimburses it, by which time it is an
 * expense and not a liability. Between approval and payday the figure exists and appears nowhere.
 *
 * **And what has been paid cannot be reconciled either**, which the report says rather than leaving as an
 * apparent omission: reimbursements post to the account `expense_reimbursement` maps, and the shipped
 * mapping points it at the same code as `meal_recovery`. An account holding two unrelated flows cannot be
 * attributed to either, so a comparison against it would prove nothing and imply something.
 *
 * **Named `ExpenseClaimsReport` rather than `ExpenseClaims`**, because a Filament page derives its slug from
 * its class name and `expense-claims` is already the ExpenseClaim *resource's* URL. Registered under the
 * shorter name, the page's route was never defined and the hub could not build a link to it — which surfaced
 * as `Route [filament.admin.pages.expense-claims] not defined` the first time anything asked for the
 * catalogue. The report and the register are two screens about one subject and want two names.
 */
class ExpenseClaimsReport extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $title = 'Expense Claims';

    protected static ?int $navigationSort = 47;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            // `expense-claims-report`, not `expense-claims`: that slug is the ExpenseClaim *resource's* own
            // help — how to submit, decide and get reimbursed — and this page is a report about the same
            // subject. Two screens about one thing need two documents.
            HelpAction::make('expense-claims-report', 'Expense Claims: Help'),
        ];
    }
}
