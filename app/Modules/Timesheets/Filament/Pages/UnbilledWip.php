<?php

namespace App\Modules\Timesheets\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Hours worked, approved, and never invoiced.
 *
 * `docs/reports-expansion-plan.md` Phase 2.3: "a balance-sheet figure that is invisible today; also the
 * report that shows revenue being lost to unbilled time."
 *
 * **A balance as at a date, not a period.** An hour booked in March and still unbilled in August is exactly
 * the hour worth seeing, and a month-scoped view is the one shape guaranteed to hide it.
 *
 * **The second Phase 2 report with nothing to tie to.** Nothing here posts unbilled work in progress — there
 * is no WIP account for timesheet hours, and construction's WIP is a different thing about a different
 * subject — so the report says so rather than inventing a comparison.
 *
 * **It reads three modules and needs no special gating**, which is worth recording because the plan's risk
 * list expects otherwise. Timesheets *requires* Projects and Employees in its manifest, and
 * `Modules::enabledFor()` walks requirements recursively — so this page is already unavailable when Projects
 * is off. Invoicing is the one module it reads without requiring, and there it degrades rather than gates:
 * the customer column falls back to an id, because a company that invoices elsewhere should still be able to
 * see what is outstanding.
 */
class UnbilledWip extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Unbilled WIP';

    protected static ?int $navigationSort = 44;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('unbilled-wip', 'Unbilled WIP: Help'),
        ];
    }
}
