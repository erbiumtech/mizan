<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Profit and loss, read by project or by department — `docs/erpnext-gap-plan.md` Phase 1, item 4.
 *
 * **A report of its own rather than a filter on the profit and loss, and that is the gap plan's own
 * constraint rather than timidity.** Every phase in that document is additive, and its test is one
 * question: *if this is only half done, does anything that works today work differently?* A dimension
 * picker bolted onto `ProfitAndLoss` would put a new grouping inside the statement this company closes its
 * year with; a second report answers the same question and cannot change the first one's figures.
 *
 * The two reports must nevertheless agree, and they do by construction: every posted income and expense
 * line in the period lands in exactly one bucket here, so this report's total *is* that statement's net
 * profit. `LedgerDimensionReportTest` asserts it, because a management report that quietly disagrees with
 * the statutory one is worse than no management report.
 *
 * **What it cannot show is on the screen, not in a footnote.** Postings a person typed have no document
 * behind them and appear as *Unassigned* — as do stock movements and petty cash, which know no project and
 * no department. Folding that row into the totals would make an incomplete grouping look complete, which
 * is the one outcome the plan names as worse than not having the dimension at all.
 */
class ProfitAndLossByDimension extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'P&L by Dimension';

    protected static ?int $navigationSort = 13;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('profit-and-loss-by-dimension', 'P&L by Dimension: Help'),
        ];
    }
}
