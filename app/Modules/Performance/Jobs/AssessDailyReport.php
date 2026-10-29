<?php

namespace App\Modules\Performance\Jobs;

use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Services\ReportVerdicts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The verdict, after the submission has returned — docs/saas-launch-plan.md §8.3.
 * Tenant-aware by the package default: the report belongs to a company, and
 * the tenant travels with the job.
 */
class AssessDailyReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $reportId) {}

    public function handle(ReportVerdicts $verdicts): void
    {
        $report = DailyReport::query()->with('employee')->find($this->reportId);

        if ($report === null || $report->hasVerdict() || $report->isDecided()) {
            return;
        }

        $verdicts->assess($report);
    }
}
