<?php

namespace App\Modules\Campaigns\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What each campaign reached, what it did not, and why.
 *
 * `docs/reports-expansion-plan.md` Phase 3.11: "sends, failures and reasons per campaign."
 *
 * **The skip count is the point.** `CampaignSend` says so itself: `skipped_no_consent` "has to be reported as
 * a distinct figure rather than a silence — otherwise nobody can tell a campaign that reached nobody from one
 * that was never sent". Every other view of a campaign shows what went out; this shows what did not.
 *
 * The two skip reasons are kept apart because they are different problems. Somebody who never agreed is a
 * list-building fault pointing at the consent register. Somebody who withdrew in the gap between preparing and
 * sending is the guard working — that gap is, in `CampaignSender`'s words, "exactly when a complaint comes
 * from", and the message did not go.
 *
 * And a campaign marked sent with rows still pending never finished, which nothing else notices because the
 * campaign's own status says it went out.
 */
class CampaignPerformance extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $title = 'Campaign Performance';

    protected static ?int $navigationSort = 48;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('campaign-performance', 'Campaign Performance: Help'),
        ];
    }
}
