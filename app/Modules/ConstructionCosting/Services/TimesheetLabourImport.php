<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Support\TimesheetImportSummary;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Support\ModuleMap;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Timesheet entries into labour records — `docs/construction-management-plan.md` §7.1, Phase 7d.
 *
 * **"Where Timesheets *is* licensed, its entries import into labour records rather than being read in place."** The
 * plan is explicit about the direction and §7.1 gives the reason: `timesheet_entries` bills and never costs. Its rate
 * ladder resolves what time is *billed* at — its own migration comment says so — and there is no cost rate and no
 * burden anywhere in it. Reading those entries as cost would mean pricing a job at charge-out rates, which overstates
 * every margin by the mark-up. **Billing keeps its ladder; costing gets its own.**
 *
 * So an entry is *copied* into a draft labour record, and §7's own machinery prices it: the rate ladder of §7.2 as at
 * the day worked, the snapshot at approval, burden as its own entry. The `source` morph is what links the two, and it
 * is what makes a second run of the same fortnight import nothing.
 *
 * **Drafts, never approved.** An import that booked cost directly would bypass the approval that snapshots the rate and
 * would let a timesheet entry reach a job with nobody having read it. Approval is a person, here as everywhere else in
 * this suite.
 *
 * **Guarded rather than required** (§18.1): without Timesheets this does nothing and site sheets are the only path,
 * "which is the primary path anyway" — most site labour is not on a timesheet and never will be.
 *
 * Four things an entry needs before it can become a labour record, and each absence is reported rather than guessed:
 *
 *  - **Approval.** An unapproved entry is time nobody has agreed, and costing a job from it puts a figure on a report
 *    that the person who typed it can still change.
 *  - **A job.** `timesheet_entries.project_id` is not null and points at `projects`, so the bridge is
 *    `construction_jobs.project_id` — a job that names the project. An entry on a project no job claims belongs to
 *    somebody else's work.
 *  - **A worker.** Matched on `construction_workers.employee_id`. Deliberately **not** created on the fly: a worker
 *    register that fills itself from timesheets is a register nobody chose the contents of, and §7.1's whole argument
 *    is about who belongs in it.
 *  - **A cost code**, from the worker's trade. A timesheet entry has none — it has a project and a task — so the trade
 *    is the only honest source, and a trade with no default code cannot be costed without somebody deciding.
 */
class TimesheetLabourImport
{
    public function __construct(private readonly LabourRecordService $records) {}

    public function isAvailable(): bool
    {
        return modules()->enabled('timesheets');
    }

    /** What an import would do, writing nothing — the same shape Attendance's importer keeps. */
    public function preview(string $from, string $to, ?Job $job = null): TimesheetImportSummary
    {
        return $this->run($from, $to, $job, previewOnly: true);
    }

    public function import(string $from, string $to, ?Job $job = null): TimesheetImportSummary
    {
        return $this->run($from, $to, $job, previewOnly: false);
    }

    private function run(string $from, string $to, ?Job $job, bool $previewOnly): TimesheetImportSummary
    {
        if (! $this->isAvailable()) {
            throw new InvalidArgumentException(
                'Importing labour needs the Timesheets module, which owns the entries. Without it, site sheets are '
                .'the only path — and they are the primary one anyway.'
            );
        }

        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        if ($to < $from) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        $jobsByProject = $this->jobsByProject($job);

        if ($jobsByProject->isEmpty()) {
            return new TimesheetImportSummary(
                skipped: [$job !== null
                    ? "{$job->code} names no project, so no timesheet entry can be matched to it. Set the project on the job first."
                    : 'No construction job names a project, so there is nothing for a timesheet entry to be matched to.'],
                previewOnly: $previewOnly,
            );
        }

        $entries = TimesheetEntry::query()
            ->approved()
            ->whereIn('project_id', $jobsByProject->keys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $imported = 0;
        $already = 0;
        $skipped = [];

        // Every worker keyed by the employee they are, and every trade's default code — two queries rather than two
        // per row, because a fortnight of a hundred people is two hundred entries (§18.3).
        $workers = Worker::query()->active()->whereNotNull('employee_id')->get()->keyBy('employee_id');
        $tradeCodes = Trade::query()->pluck('default_cost_code_id', 'id');
        $codes = CostCode::query()->whereIn('id', $tradeCodes->filter()->values())->get()->keyBy('id');

        foreach ($entries as $entry) {
            if ($this->alreadyImported($entry)) {
                $already++;

                continue;
            }

            $worker = $workers->get($entry->employee_id);

            if ($worker === null) {
                $skipped[] = "Employee #{$entry->employee_id} on {$entry->date->toDateString()}: nobody in the worker "
                    .'register is linked to that employee record. Add them under Workers, engaged as an employee.';

                continue;
            }

            $code = $codes->get($tradeCodes->get($worker->trade_id));

            if ($code === null) {
                $skipped[] = "{$worker->displayName()} on {$entry->date->toDateString()}: "
                    .($worker->trade_id === null
                        ? 'no trade is set on the worker, so there is no cost code to book to.'
                        : 'their trade has no usual cost code, so there is nothing to book to. Set one on the trade.');

                continue;
            }

            if ((int) $entry->minutes <= 0) {
                $skipped[] = "{$worker->displayName()} on {$entry->date->toDateString()}: the entry records no time.";

                continue;
            }

            if ($previewOnly) {
                $imported++;

                continue;
            }

            try {
                TenantTransaction::run(fn () => $this->records->record(
                    $worker,
                    $jobsByProject->get($entry->project_id),
                    $code,
                    [
                        'worked_on' => $entry->date->toDateString(),
                        // Every minute as normal time: `timesheet_entries` has no overtime split, and inventing one
                        // from a threshold would price hours at a multiple nobody agreed. Overtime is entered on a
                        // site sheet, where somebody says so.
                        'normal_minutes' => (int) $entry->minutes,
                        'description' => $entry->task ?: $entry->description,
                        'source_type' => $entry::class,
                        'source_id' => $entry->getKey(),
                    ],
                ));

                $imported++;
            } catch (InvalidArgumentException $e) {
                // The guards of `LabourRecordService` reported per row rather than failing the run: the commonest is a
                // worker already booked a full day from a site sheet, which is a real conflict somebody must resolve
                // and not a reason to abandon the other hundred entries.
                $skipped[] = "{$worker->displayName()} on {$entry->date->toDateString()}: {$e->getMessage()}";
            }
        }

        return new TimesheetImportSummary(
            imported: $imported,
            alreadyImported: $already,
            skipped: $skipped,
            previewOnly: $previewOnly,
        );
    }

    /**
     * The construction jobs that name a project, keyed by that project.
     *
     * `construction_jobs.project_id` is the whole bridge, and it is an unconstrained column rather than a foreign key —
     * so this reads an integer and never names `Project`. That is what keeps `projects` out of this module's import
     * graph while still letting the two meet (§18.1).
     *
     * @return Collection<int, Job>
     */
    private function jobsByProject(?Job $job): Collection
    {
        return Job::query()
            ->whereNotNull('project_id')
            ->when($job, fn ($query) => $query->whereKey($job->getKey()))
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Whether a previous run already brought this entry in.
     *
     * Keyed on the morph, and it counts a **reversed** record too: an entry whose record was reversed on purpose must
     * not quietly come back on the next import, which would undo somebody's decision and look like the import working.
     */
    private function alreadyImported(TimesheetEntry $entry): bool
    {
        return LabourRecord::query()
            ->where('source_type', ModuleMap::alias($entry::class))
            ->where('source_id', $entry->getKey())
            ->exists();
    }
}
