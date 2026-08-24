<?php

namespace App\Modules\Performance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Whether each review cycle actually finished.
 *
 * `docs/reports-expansion-plan.md` Phase 3.12: "reviews and goals complete per cycle, one-to-ones held."
 *
 * **"Complete" is the question, and `Review` already answers it.** Only *acknowledged* is a review that
 * finished; the rung before it is the one that matters, because `isVisibleToEmployee()` is `shared_at !== null`
 * and "submitted is not shared". So a **closed cycle holding unshared reviews** is the finding: somebody wrote
 * a review of a person, the cycle was closed, and the person never saw it. On every other screen a review
 * sitting at *manager submitted* looks like work done.
 *
 * A closed cycle with open goals is the same failure in the other column — `Goal` has *missed* among its
 * settled states, so leaving one open is nobody having decided rather than a kindness.
 *
 * Named `ReviewCycleProgress` because `ReviewCycleResource` already derives the `review-cycles` slug.
 */
class ReviewCycleProgress extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $title = 'Review Cycle Progress';

    protected static ?int $navigationSort = 49;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('review-cycle-progress', 'Review Cycle Progress: Help'),
        ];
    }
}
