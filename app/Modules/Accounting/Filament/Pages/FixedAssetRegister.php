<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * The asset register as a note to the accounts — `docs/reports-expansion-plan.md` Phase 2.5.
 *
 * `FixedAssetResource` already lists assets, and a list is not a note: it answers "what do we own" one row at
 * a time and cannot answer "what is it all worth, and do the accounts say so too". This page states cost,
 * depreciation to date and net book value with both totals tied to the ledger — the asset accounts on one
 * side, account 1500 on the other — and adds the charge still to come over the next twelve months.
 *
 * **The forecast needed a method that did not exist.** The plan costed this report as "no new business
 * logic", on the basis that the twelve-month charge came from `DepreciationService`'s own method. Every
 * method it had posted journal entries, so there was no way to ask what the next year's charge would be
 * without booking the next year's depreciation. `DepreciationService::schedule()` was written for this page
 * and books nothing.
 *
 * Built on `ReportRenderers` rather than an arm of `ReportPane`'s `match`, like the rest of Phases 1 and 2.
 */
class FixedAssetRegister extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Fixed Asset Register';

    protected static ?int $navigationSort = 14;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('fixed-asset-register', 'Fixed Asset Register: Help'),
        ];
    }
}
