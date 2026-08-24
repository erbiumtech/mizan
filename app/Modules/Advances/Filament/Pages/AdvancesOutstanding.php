<?php

namespace App\Modules\Advances\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What staff owe the company, and whether the accounts say the same.
 *
 * `docs/reports-expansion-plan.md` Phase 2.7: "a receivable from staff; feeds final settlement, so a wrong
 * figure leaves the company out of pocket." The register existed as a resource — a row per advance — and a
 * list cannot answer the one question a balance sheet asks, which is the total.
 *
 * **The commonest difference is nobody's mistake, and the report says so first.** Nothing posts an advance
 * when it is entered: the register records that money was lent, and the ledger only learns of it if the
 * payment out was booked against the advances account. A payslip's recovery *credits* that account, so a
 * company that pays advances from the bank without booking them has every advance here and only the
 * recoveries there. Phase 2.5 found the same shape in asset cost — a register is not a posting.
 */
class AdvancesOutstanding extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $title = 'Advances Outstanding';

    protected static ?int $navigationSort = 46;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('advances-outstanding', 'Advances Outstanding: Help'),
        ];
    }
}
