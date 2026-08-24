<?php

namespace App\Modules\Quotations\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What was quoted, what was won, and what was actually billed.
 *
 * `docs/reports-expansion-plan.md` Phase 3.3. Two conversions rather than one: issued → accepted is whether
 * the work was won, and accepted → invoiced is whether anybody billed for it. The second is the one nothing
 * else in the application surfaces, and an accepted quote with no invoice against it is revenue the company
 * has agreed and never asked for.
 *
 * **Superseded versions are excluded from every figure.** A quote revised three times is one opportunity, and
 * counting each version would inflate what was issued by however often the company negotiates — driving the
 * win rate down for doing the thing that wins work.
 */
class QuotationConversion extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $title = 'Quotation Conversion';

    protected static ?int $navigationSort = 50;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('quotation-conversion', 'Quotation Conversion: Help'),
        ];
    }
}
