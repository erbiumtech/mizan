<?php

namespace App\Modules\Attendance\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * A month of attendance for everybody, and what payroll prorated on.
 *
 * `docs/reports-expansion-plan.md` Phase 3.1. The figures existed per employee — `AttendanceMonth` has
 * computed paid days, loss of pay and overtime all along — and nothing aggregated them, so a month could be
 * read one person at a time and never as a month.
 *
 * **The comparison is the point.** A payslip stores the `paid_days` it prorated on, so this register either
 * reproduces that figure or has found a disagreement — and a disagreement means pay was calculated on
 * something this calendar does not produce. The note counts them.
 *
 * An employee × day grid, so it declares itself `wide`: thirty-one day columns plus four totals is well past
 * what the pane is wide, and before Phase 0.2 the extra ones were silently clipped.
 */
class AttendanceRegister extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $title = 'Attendance Register';

    protected static ?int $navigationSort = 48;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('attendance-register', 'Attendance Register: Help'),
        ];
    }
}
