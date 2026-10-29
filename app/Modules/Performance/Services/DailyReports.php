<?php

namespace App\Modules\Performance\Services;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Jobs\AssessDailyReport;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Notifications\RecordChanged;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Filing and deciding — docs/saas-launch-plan.md §8.3.
 *
 * Filing is the employee's act: one report per date, re-filed in place until a
 * manager decides, completing the day's daily-report task if one was rolled.
 * Deciding is the manager's: accept the verdict and the ledger gets the points
 * the verdict implies, or override with points of their own. The model is
 * never on the ledger by itself.
 */
class DailyReports
{
    public function __construct(private readonly MeritLedger $ledger, private readonly TaskRoller $tasks, private readonly ReportVerdicts $verdicts) {}

    public function submit(Employee $employee, string $date, string $body, ?User $by = null): DailyReport
    {
        $date = Carbon::parse($date)->toDateString();

        if (trim($body) === '') {
            throw new InvalidArgumentException('A daily report says what was done; an empty one says nothing.');
        }

        $report = DailyReport::query()->where('employee_id', $employee->getKey())->whereDate('report_date', $date)->first();

        if ($report?->isDecided()) {
            throw new InvalidArgumentException("The report for {$date} has been decided and cannot be re-filed.");
        }

        $report ??= new DailyReport(['employee_id' => $employee->getKey(), 'report_date' => $date]);
        $report->forceFill([
            'body' => $body,
            'submitted_at' => now(),
            // A re-filed report is re-read: the old verdict was about the old words.
            'verdict' => null, 'verdict_summary' => null, 'verdict_questions' => null, 'verdict_at' => null, 'verdict_model' => null,
        ])->save();

        $this->completeTheDaysTask($employee, $date, $by);

        if ($this->verdicts->available()) {
            AssessDailyReport::dispatch($report->getKey());
        }

        return $report->fresh();
    }

    /**
     * Accept the verdict, or override it with points of one's own — and either way
     * a person writes the ledger, once per report.
     */
    public function decide(DailyReport $report, User $manager, string $decision, ?int $points = null, ?string $note = null): DailyReport
    {
        if ($report->isDecided()) {
            throw new InvalidArgumentException('This report has already been decided.');
        }

        if ($decision === DailyReport::DECISION_ACCEPTED) {
            if (! $report->hasVerdict()) {
                throw new InvalidArgumentException('There is no verdict to accept; override with your own points instead.');
            }

            $points = (int) $this->pointsForVerdict($report->verdict);
        } elseif ($decision === DailyReport::DECISION_OVERRIDDEN) {
            $points = (int) ($points ?? 0);
        } else {
            throw new InvalidArgumentException("Unknown decision [{$decision}].");
        }

        $entry = null;

        if ($points !== 0) {
            $entry = $this->ledger->record(
                $report->employee_id,
                $points,
                'Daily report for '.$report->report_date->toDateString().($note ? ": {$note}" : ''),
                MeritEntry::SOURCE_REPORT,
                $report->getKey(),
                $manager,
                $report->report_date->toDateString(),
            );
        }

        $report->forceFill([
            'manager_decision' => $decision,
            'decided_by' => $manager->getKey(),
            'decided_at' => now(),
            'manager_note' => $note,
            'merit_entry_id' => $entry?->getKey(),
        ])->save();

        $report->employee?->user?->notify(new RecordChanged(
            'Your daily report was reviewed',
            $report->report_date->toDateString().': '.($points === 0 ? 'noted, no points.' : sprintf('%+d points.', $points)).($note ? " {$note}" : ''),
        ));

        return $report->fresh();
    }

    public function pointsForVerdict(?string $verdict): int
    {
        $table = (array) setting('performance.merit.report_points', config('performance.merit.report_points', []));

        return (int) ($table[$verdict] ?? 0);
    }

    private function completeTheDaysTask(Employee $employee, string $date, ?User $by): void
    {
        $occurrence = TaskOccurrence::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('due_on', $date)
            ->whereHas('task', fn ($q) => $q->where('kind', RecurringTask::KIND_DAILY_REPORT))
            ->open()
            ->first();

        if ($occurrence) {
            $this->tasks->complete($occurrence, 'Report filed', $by);
        }
    }
}
