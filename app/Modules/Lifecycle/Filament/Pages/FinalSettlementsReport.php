<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What each leaver was owed, and what it was made of.
 *
 * `docs/reports-expansion-plan.md` Phase 3.8: "composition per leaver: notice recovery, encashment,
 * gratuity, advance and asset recoveries, net."
 *
 * **There is no ledger balance to tie to, and that is not an omission.** A settlement is a proposal —
 * nothing posts until somebody approves it and puts it through a payslip or a payment. So the report's value
 * is in three disagreements no other screen can see: a leaver nobody built a settlement for, a stored net
 * that is no longer the sum of its parts, and a draft still quoting kit that has since come back.
 *
 * It lists **leavers**, not settlements, which is what makes the first of those visible at all.
 */
/*
 * Named `...Report` because `FinalSettlementResource` already derives the `final-settlements` slug, and two
 * things claiming one URL is a missing route rather than a clash Filament reports. The same reason
 * `ExpenseClaimsReport` carries the suffix.
 */
class FinalSettlementsReport extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $title = 'Final Settlements';

    protected static ?int $navigationSort = 45;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('final-settlements-report', 'Final Settlements: Help'),
        ];
    }
}
