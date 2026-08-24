<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Every credit note issued, and whether it was allowed to be.
 *
 * `docs/reports-expansion-plan.md` Phase 3.5: "a tax-sensitive list with commissioner approval status; only
 * visible per invoice today."
 *
 * **The tax sensitivity is the report.** A credit note may be issued against an invoice for a limited number
 * of days — 180 by default — and beyond that it needs the Commissioner's approval under rule 22. Nothing in
 * this application refuses a credit note outside that window; the rule is *reported*, as the SLA clocks are.
 * So this list is the only place a reversal made without cover is visible, and it is stated as an amount
 * because what matters is how much tax was reversed rather than how many documents did it.
 */
class CreditNotesIssued extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $title = 'Credit Notes Issued';

    protected static ?int $navigationSort = 52;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('credit-notes-issued', 'Credit Notes Issued: Help'),
        ];
    }
}
