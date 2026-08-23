<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * The loan book: what is left on every loan, and whether the accounts agree.
 *
 * `LoanService`'s schedule was rendered in exactly one place — the relation manager inside a single loan —
 * so a company could read any one amortisation table and could not answer "what do we owe". See
 * `docs/reports-expansion-plan.md` Phase 1.6: "there is no portfolio view".
 *
 * **The reconciliation is the report.** It states what the schedules say is outstanding beside what the
 * liability accounts say, and names the difference. Those drift apart for real reasons — an instalment paid
 * outside the application, a manual entry against the account, a loan restructured without rebuilding its
 * table — and each of them is something somebody needs to be told.
 *
 * Built on `ReportRenderers` rather than by adding an arm to `ReportPane`'s `match`, which is how
 * Accounting's older eleven are drawn. The newer path gives the page, the date and the gate for free and
 * keeps the pane from growing a method per report; the pane asks `ReportRenderers` first, so both reach the
 * same closure.
 */
class LoansOutstanding extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Loans Outstanding';

    protected static ?int $navigationSort = 12;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('loans-outstanding', 'Loans Outstanding: Help'),
        ];
    }
}
