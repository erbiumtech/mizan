<?php

namespace App\Modules\Crm\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * How much is in the pipeline, by stage, weighted and plain.
 *
 * The report a sales meeting opens with, and the one figure the rest of this set is read against.
 * `PipelineReports::byStage()` has computed it since CRM shipped and nothing outside `SalesTargetResource`
 * ever called it — see `docs/reports-expansion-plan.md` Phase 1.2, whose premise is that the computation
 * is the part that already exists.
 *
 * Both columns, deliberately: the weighted figure is what a forecast is built on and the plain one is what
 * is actually on the table, and a stage list showing only one of them invites the other to be guessed at.
 */
class PipelineByStage extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $title = 'Pipeline by Stage';

    protected static ?int $navigationSort = 20;

    protected function reportActions(): array
    {
        // Literal, in this file, on every one of the five. HelpCoverageTest reads each page's own source
        // for `HelpAction::make('...')` — a call inherited from a shared parent is a page with no help as
        // far as that test can tell, and it is right to say so: the slug is per report, not per base class.
        return [
            HelpAction::make('crm-pipeline-reports', 'Pipeline by Stage: Help'),
        ];
    }
}
