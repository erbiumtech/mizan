<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What will hit the bank in the next ninety days, and whether it has been raised.
 *
 * `docs/reports-expansion-plan.md` Phase 1.7's note on this one is "nothing in the application answers this
 * today", and it was right: the scheduled entries, the beneficiary subscriptions and the recurring invoices
 * each had a runner that raised them and no screen that listed what was coming. A company could see
 * everything it had been billed for and nothing it had committed to.
 *
 * **Every row is a commitment, not a certainty**, which is why *Raised* is a column. What is raised is a
 * payable somebody can chase; what is not is a decision still open, and presenting the two as one list of
 * facts would make this a forecast the ledger had to honour.
 *
 * The three sources are *registered* rather than imported — see `App\Support\CashCommitments`. Recurring
 * invoices belong to Invoicing, and one column of one report is not a reason to buy back the
 * `accounting -> invoicing` edge that packaging §8 removed.
 */
class CashCommitments extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $title = 'Cash Commitments';

    protected static ?int $navigationSort = 13;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('cash-commitments', 'Cash Commitments: Help'),
        ];
    }
}
