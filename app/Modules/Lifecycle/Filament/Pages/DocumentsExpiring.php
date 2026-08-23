<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Every document about to lapse, with how long is left.
 *
 * `DocumentExpiryCheck` had one caller — the daily command that mails a warning — so the state of every
 * visa, licence and contract in the company was knowable and never viewable. See
 * `docs/reports-expansion-plan.md` Phase 1.5: "compliance-critical and currently only ever emailed".
 *
 * **Reads the listing, not the notification query.** `due()` suppresses a document once its threshold has
 * been warned at, which is right for a daily mail and would have made this report emptiest on the company
 * that had been most diligent about sending them.
 */
class DocumentsExpiring extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $title = 'Documents Expiring';

    protected static ?int $navigationSort = 42;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('documents-expiring', 'Documents Expiring: Help'),
        ];
    }
}
