<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Lifecycle\Models\EmployeeChecklistItem;
use App\Support\TenantTransaction;
use Illuminate\Support\Carbon;

/**
 * Starting somebody on a checklist, and marking it off.
 *
 * The items are **copied** from the template, not joined to it. A template edited or
 * retired next year must not change what a leaver was actually asked to do in March —
 * the same reasoning that copies `is_paid` onto a leave day and records the proration
 * divisor on a payslip.
 */
class ChecklistService
{
    /**
     * Start a checklist for an employee from a template.
     *
     * Due dates are computed from the anchor — the joining date for onboarding, the
     * leaving date for exit — using each item's signed offset, so "-7" is a week before
     * somebody arrives, which is when most onboarding actually has to happen.
     */
    public function start(
        Employee $employee,
        ChecklistTemplate $template,
        string|Carbon|null $anchor = null,
    ): EmployeeChecklist {
        $anchor = Carbon::parse($anchor ?? match ($template->kind) {
            ChecklistTemplate::KIND_EXIT => $employee->left_on ?? now(),
            default => $employee->date_of_joining ?? now(),
        });

        return TenantTransaction::run(function () use ($employee, $template, $anchor): EmployeeChecklist {
            $checklist = EmployeeChecklist::create([
                'employee_id' => $employee->getKey(),
                'checklist_template_id' => $template->getKey(),
                'kind' => $template->kind,
                'started_on' => now()->toDateString(),
            ]);

            foreach ($template->items as $item) {
                $checklist->items()->create([
                    'title' => $item->title,
                    'owner_role' => $item->owner_role,
                    'due_on' => $anchor->copy()->addDays($item->due_offset_days)->toDateString(),
                    'sort' => $item->sort,
                ]);
            }

            return $checklist->refresh();
        });
    }

    public function complete(EmployeeChecklistItem $item, User $by, ?string $note = null): EmployeeChecklistItem
    {
        $item->update([
            'completed_at' => now(),
            'completed_by' => $by->getKey(),
            'note' => $note ?? $item->note,
        ]);

        $this->closeIfFinished($item->checklist);

        return $item;
    }

    public function reopen(EmployeeChecklistItem $item): EmployeeChecklistItem
    {
        $item->update(['completed_at' => null, 'completed_by' => null]);

        // The checklist reopens with it: a run with an outstanding item is not complete,
        // whatever it said a moment ago.
        $item->checklist->update(['completed_on' => null]);

        return $item;
    }

    /**
     * Close the run when nothing is outstanding.
     *
     * Derived rather than set by hand, so a checklist cannot read as complete while an
     * item on it is not — which is the one thing an exit checklist must never do, since
     * a final settlement reads it to know whether the laptop came back.
     */
    private function closeIfFinished(EmployeeChecklist $checklist): void
    {
        $checklist->update([
            'completed_on' => $checklist->isComplete() ? now()->toDateString() : null,
        ]);
    }
}
