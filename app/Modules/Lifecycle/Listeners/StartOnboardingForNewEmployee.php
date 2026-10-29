<?php

namespace App\Modules\Lifecycle\Listeners;

use App\Modules\Employees\Events\EmployeeJoined;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Services\ChecklistService;
use Filament\Notifications\Notification;

/**
 * Hire starts the onboarding checklist — docs/saas-launch-plan.md §7.3.
 *
 * Exactly one active onboarding template: start it, with the due dates
 * ChecklistService computes from the joining date. None, or two: start nothing
 * and say so, because guessing between two templates is how somebody is handed
 * the wrong laptop order, and silently starting nothing is how nobody notices
 * until the first morning. No default flag until somebody has two.
 *
 * Guarded on the module, not on the listener being registered: the provider
 * registers it at boot for every company, and the licence is a per-request fact.
 */
class StartOnboardingForNewEmployee
{
    public function __construct(private readonly ChecklistService $checklists) {}

    public function handle(EmployeeJoined $event): void
    {
        if (! modules()->enabled('lifecycle')) {
            return;
        }

        $templates = ChecklistTemplate::query()
            ->active()
            ->ofKind(ChecklistTemplate::KIND_ONBOARDING)
            ->with('items')
            ->get();

        if ($templates->count() !== 1) {
            Notification::make()
                ->warning()
                ->title('No onboarding checklist started')
                ->body($templates->isEmpty()
                    ? 'There is no active onboarding template. Add one under Lifecycle → Checklist templates and start it from the employee.'
                    : "There are {$templates->count()} active onboarding templates, so none was chosen. Start the right one from the employee.")
                ->send();

            return;
        }

        $checklist = $this->checklists->start($event->employee, $templates->first());

        Notification::make()
            ->success()
            ->title('Onboarding checklist started')
            ->body("{$templates->first()->name}: {$checklist->items()->count()} items, dated from the joining date.")
            ->send();
    }
}
