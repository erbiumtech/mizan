<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What is still outstanding on people's onboarding and exit checklists.
 *
 * `docs/reports-expansion-plan.md` Phase 3.9: "checklist items overdue by owner role, from
 * `employee_checklist_items.due_on`."
 *
 * Read as a **progress** report rather than only an overdue list, which is what the plan's own title asks
 * for — an overdue-only list cannot tell a checklist that is behind from one nobody started.
 *
 * Two of its three findings are items an overdue report structurally cannot show: an item with **no due
 * date**, which can never become overdue and so will never be chased, and one with **no owner role**, which
 * nobody has been asked to do. The third has a security edge — an **exit item still open for somebody who
 * has already left** is a door still unlocked.
 */
class ChecklistProgress extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Onboarding / Offboarding Progress';

    protected static ?int $navigationSort = 46;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('checklist-progress', 'Onboarding / Offboarding Progress: Help'),
        ];
    }
}
