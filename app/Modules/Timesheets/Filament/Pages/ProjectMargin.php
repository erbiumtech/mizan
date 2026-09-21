<?php

namespace App\Modules\Timesheets\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Revenue against the cost of the hours, per project.
 *
 * The one question a services company asks about every client, and the one this application could not answer:
 * revenue by project came from invoices and labour cost landed under the department, because a payslip knows a
 * person and not a project. `TimesheetService::projectMargin()` joins the two through the `LabourCost` contract.
 */
class ProjectMargin extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Project Margin';

    protected static ?int $navigationSort = 45;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('project-margin', 'Project Margin: Help'),
        ];
    }
}
