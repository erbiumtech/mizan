<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Tax withheld from suppliers, as the §165 statement asks for it — `docs/erpnext-gap-plan.md` Phase 4, item 4.
 *
 * The year-end question about the deductions a company made from what it paid out: payroll answers it for
 * salaries — §149, through `FbrTaxFile` — and nothing answered it for §153. Assembling it by hand meant
 * reading a year of journal entries and reconstructing, from an amount, the gross it was a percentage of.
 *
 * **A listing rather than a file, deliberately.** `FbrTaxFile` produces something to upload because IRIS
 * takes an upload for salary; the §153 statement is keyed in or attached, and what a person needs is the
 * page they can check against the challans they paid. Built the same way regardless — a page, a renderer,
 * and one line in the catalogue — so it exports through `ExportsTheOpenReport` like every other report.
 */
class WithholdingStatement extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scissors';

    protected static ?string $title = 'Tax Withheld (§165)';

    protected static ?int $navigationSort = 14;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('withholding-statement', 'Tax Withheld: Help'),
        ];
    }
}
