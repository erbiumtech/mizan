<?php

namespace App\Modules\Lifecycle\Listeners;

use App\Modules\Employees\Events\EmployeeJoined;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Services\ChecklistService;
use Filament\Notifications\Notification;

/**
 * Hire starts the onboarding checklist — docs/saas-launch-plan.md §7.3.
 *
 * The template is ChecklistService::defaultTemplate(): the company's chosen default
 * (Company Settings → People → Onboarding) when several are active, the only one when
 * there is one. None, or several and no default: start nothing and say so, because
 * guessing between two templates is how somebody is handed the wrong laptop order,
 * and silently starting nothing is how nobody notices until the first morning. The
 * employee's page has Start checklist for exactly that case.
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

        // Once per joining — docs/saas-launch-plan.md §14.5. The event now fires for every new employee, and a
        // hire fires it as well; a checklist already running for this person is the one that counts.
        if (\App\Modules\Lifecycle\Models\EmployeeChecklist::query()->where('employee_id', $event->employee->getKey())->where('kind', ChecklistTemplate::KIND_ONBOARDING)->whereNull('completed_on')->exists()) {
            return;
        }

        $template = $this->checklists->defaultTemplate(ChecklistTemplate::KIND_ONBOARDING);

        if ($template === null) {
            $active = ChecklistTemplate::query()->active()->ofKind(ChecklistTemplate::KIND_ONBOARDING)->count();

            Notification::make()
                ->warning()
                ->title('No onboarding checklist started')
                ->body($active === 0
                    ? 'There is no active onboarding template. Add one under Lifecycle → Checklist templates, then use Start checklist on the employee.'
                    : "There are {$active} active onboarding templates and no default. Choose one under Company Settings → People → Onboarding, or use Start checklist on the employee.")
                ->send();

            return;
        }

        $checklist = $this->checklists->start($event->employee, $template);

        Notification::make()
            ->success()
            ->title('Onboarding checklist started')
            ->body("{$template->name}: {$checklist->items()->count()} items, dated from the joining date.")
            ->send();
    }
}
