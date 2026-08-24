<?php

namespace App\Modules\Campaigns\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Who agreed to be contacted, on what channel, when, and how.
 *
 * `docs/reports-expansion-plan.md` Phase 3.10: "consent state per contact per channel with source and date.
 * This is compliance evidence, not marketing statistics, which is why it belongs with the reports."
 *
 * **That clause governs the whole report.** No opt-in rate, no channel comparison, no trend — a marketing
 * figure answers "how are we doing" and this answers "who agreed to this, when, and how", which `Consent`'s
 * own docblock calls the only defensible answer when somebody complains.
 *
 * The state is **derived from the latest row**, resolved exactly as `Consent::permits()` resolves it, and read
 * as at a date so the question "what did we have permission for on 30 June" has an answer. The finding is a
 * grant with no source: the migration puts it plainly — "'they agreed' is worth nothing without 'and here is
 * how'".
 */
class ConsentRegister extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Consent Register';

    protected static ?int $navigationSort = 47;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('consent-register', 'Consent Register: Help'),
        ];
    }
}
