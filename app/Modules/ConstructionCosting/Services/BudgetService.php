<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\JobBudgetLine;
use App\Modules\ConstructionCosting\Models\ProgressMeasurement;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Creating, revising and approving a job's budget — `docs/construction-management-plan.md` §3.5.
 *
 * The rule this service exists for: **the baseline must not move once work has been measured against it.** A
 * revision creates a new *current* version and leaves the baseline where it is, so the cost report tracks the
 * revised budget while earned value keeps comparing against the one the job was sold on. Letting a revision move
 * the baseline makes every historical schedule variance retrospectively wrong, and nothing reports it.
 */
class BudgetService
{
    /**
     * Open a new version, copying the current one's lines when there is one.
     *
     * Copying rather than starting blank is deliberate: a revision after variation twelve differs from the budget
     * before it by a handful of lines, and retyping four hundred is how a revision comes to disagree with the
     * budget it was supposed to revise.
     */
    public function createVersion(Job $job, string $name, string $kind = JobBudget::KIND_REVISION, bool $copyCurrent = true): JobBudget
    {
        return TenantTransaction::run(function () use ($job, $name, $kind, $copyCurrent): JobBudget {
            $current = JobBudget::currentFor($job);

            $version = JobBudget::create([
                'job_id' => $job->getKey(),
                'version_no' => (int) JobBudget::query()->where('job_id', $job->getKey())->max('version_no') + 1,
                'name' => $name,
                'kind' => $kind,
                'status' => JobBudget::STATUS_DRAFT,
            ]);

            if ($copyCurrent && $current) {
                foreach ($current->lines as $line) {
                    $version->lines()->create($line->only([
                        'job_id', 'wbs_node_id', 'cost_code_id', 'cost_type',
                        'quantity', 'unit_of_measure', 'unit_rate', 'amount', 'period_start', 'description',
                    ]));
                }
            }

            return $version->refresh();
        });
    }

    /**
     * Approve a version and make it the current budget.
     *
     * The previous current version becomes `superseded` rather than being deleted — §3.5 keeps them for
     * comparison, which is the same reasoning `budgets.is_active` already gives. "What did we think the budget was
     * before VO-12" is a question somebody asks in a meeting.
     */
    public function approve(JobBudget $version): JobBudget
    {
        if ($version->status === JobBudget::STATUS_APPROVED) {
            throw new InvalidArgumentException("{$version->name} is already approved.");
        }

        if ($version->lines()->doesntExist()) {
            throw new InvalidArgumentException(
                "{$version->name} has no lines. An empty budget approved as current would read as a job with no budget at all."
            );
        }

        return TenantTransaction::run(function () use ($version): JobBudget {
            $previous = JobBudget::currentFor($version->job);

            if ($previous && $previous->getKey() !== $version->getKey()) {
                $previous->update(['status' => JobBudget::STATUS_SUPERSEDED]);
            }

            // `is_current` is stood down on every other version by the model's own saved hook.
            $version->update([
                'status' => JobBudget::STATUS_APPROVED,
                'is_current' => true,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'effective_from' => $version->effective_from ?? now()->toDateString(),
            ]);

            return $version->refresh();
        });
    }

    /**
     * Set the baseline — refused once anything has been measured against the existing one.
     *
     * This is the rule that keeps earned value meaningful. Once a measurement exists, moving the baseline restates
     * every earned-value figure ever taken on this job, and each of those was frozen precisely so that could not
     * happen. The measurement's own `measured_against_version_id` would then point at a version that is no longer
     * the baseline, and the two facts would disagree with nothing to arbitrate them.
     */
    public function setBaseline(JobBudget $version, bool $force = false): JobBudget
    {
        $existing = JobBudget::baselineFor($version->job);

        if ($existing && $existing->getKey() !== $version->getKey() && ! $force) {
            $measured = ProgressMeasurement::query()
                ->where('job_id', $version->job_id)
                ->exists();

            if ($measured) {
                throw new InvalidArgumentException(
                    "{$version->job->code} already has progress measured against baseline \"{$existing->name}\". "
                    .'Re-baselining would restate every earned-value figure taken so far. Approve this as the '
                    .'current budget instead, which is what the cost report reads.'
                );
            }
        }

        return TenantTransaction::run(function () use ($version): JobBudget {
            $version->update(['is_baseline' => true]);

            return $version->refresh();
        });
    }

    /**
     * Add a line to a draft version.
     *
     * Approved versions are not edited: §3.5's whole point is that a budget somebody edited last Tuesday is not
     * something a variance can be measured against. A change to an approved budget is a new version.
     *
     * `cost_type` is read off the code **here and stored**, the same snapshot `CostLedger::record()` takes and for
     * the same reason: re-typing a cost code in June must not restate what an approved budget said the
     * labour/material split was.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(JobBudget $version, array $attributes): JobBudgetLine
    {
        if ($version->status !== JobBudget::STATUS_DRAFT) {
            throw new InvalidArgumentException(
                "\"{$version->name}\" is {$version->status}. Create a revision rather than editing an approved budget — "
                .'a variance measured against a budget that moves means nothing.'
            );
        }

        $code = CostCode::query()->findOrFail($attributes['cost_code_id'] ?? null);

        // A heading takes no budget for the same reason it takes no cost: the budget column of the four-column
        // report rolls children up into their parent, so a figure on both counts the money twice.
        if (! $code->is_leaf) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is a heading. Budgeting against it would double-count in every rolled-up total."
            );
        }

        return $version->lines()->create($attributes + [
            'job_id' => $version->job_id,
            'cost_type' => $code->cost_type,
        ]);
    }
}
