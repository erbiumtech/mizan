<?php

namespace App\Modules\Payroll\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Employee by pay component for a month, footed against the payroll journal.
 *
 * `docs/reports-expansion-plan.md` Phase 2.1 calls this "the single most-asked-for payroll report", and its
 * note on why it was missing is exact: "today only per-payslip views exist". A company could open any one
 * payslip and could not see a month.
 *
 * **The tie to the ledger is the report.** Phase 2's rule is that each of its reports carries a record row
 * tying to a balance, and here it is exact rather than approximate: the payroll entry credits salaries
 * payable with each payslip's net salary, so the register's net total must equal that credit across the
 * month's posted payslips. Where it does not, the report says which of the two reasons applies — a payslip
 * not yet posted, which is ordinary, or a difference with everything posted, which is not.
 *
 * The first report in the application to have more columns than the pane is wide, and the reason Phase 0.2's
 * scroll wrapper exists: before it, the components that did not fit were silently clipped.
 */
class PayrollRegister extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $title = 'Payroll Register';

    protected static ?int $navigationSort = 5;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('payroll-register', 'Payroll Register: Help'),
        ];
    }
}
