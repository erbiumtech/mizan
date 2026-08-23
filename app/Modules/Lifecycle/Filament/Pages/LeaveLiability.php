<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What unused encashable leave would cost if everybody left today.
 *
 * `docs/reports-expansion-plan.md` Phase 2.2, whose own description is the point of it: "this is an accrual
 * that belongs in the accounts and is currently in nobody's figures".
 *
 * **It is the one Phase 2 report that ties to nothing**, and the report says so rather than hiding it. Phase
 * 2's general rule is that each of its reports carries a record row tying to a ledger balance; there is no
 * leave-liability account in this application and nothing posts one, so there is no balance to tie to.
 * Delivering that fact is the report's whole value — a provision nobody has posted is exactly as real as one
 * that has been, and the only difference is that the balance sheet does not know.
 *
 * The figure is `FinalSettlementBuilder::leaveEncashment()` and not a formula of its own, so the accrual and
 * the settlement it provides for cannot disagree.
 */
class LeaveLiability extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Leave Liability';

    protected static ?int $navigationSort = 43;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('leave-liability', 'Leave Liability: Help'),
        ];
    }
}
