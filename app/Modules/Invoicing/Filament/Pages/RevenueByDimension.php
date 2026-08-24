<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Revenue by customer, by project and by product — gross and net of credit notes.
 *
 * `docs/reports-expansion-plan.md` Phase 3.4, whose note is the reason it exists: "`invoices.project_id`
 * exists and nothing reports on it."
 *
 * **Three groupings in one table rather than a dimension filter.** A picker would have to be declared in
 * `ReportPane::ASKS`, an Accounting constant, and putting an Invoicing concept there is the coupling Phase
 * 1.2 removed. *Win/Loss* already stacks three groupings behind a labelled first column, and reading them
 * together is better than switching between them: a customer whose revenue is all on one project is a
 * different risk from one spread across four.
 *
 * **The three groupings must not be added together** — they are the same money viewed three ways — so the
 * record row totals the customer grouping alone and the note says so.
 */
class RevenueByDimension extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Revenue by Customer, Project and Product';

    protected static ?int $navigationSort = 51;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('revenue-by-dimension', 'Revenue by Dimension: Help'),
        ];
    }
}
