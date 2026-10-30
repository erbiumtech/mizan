<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Lifecycle\Services\ChecklistService;
use App\Support\TenantSettings;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Which onboarding checklist a joiner gets, and starting one by hand.
 *
 * With several active onboarding templates a joiner got none, and the warning said "start it from the
 * employee" — a page with no such action. Now the company names a default (Company Settings → People →
 * Onboarding), and the employee's page has Start checklist for anybody and any template.
 */
class OnboardingTemplateChoiceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Administrator', 'onboarding-admin@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();
    }

    private function template(string $name, string $kind = ChecklistTemplate::KIND_ONBOARDING): ChecklistTemplate
    {
        $template = ChecklistTemplate::create(['name' => $name, 'kind' => $kind, 'is_active' => true]);
        $template->items()->create(['title' => "$name item", 'kind' => 'manual', 'due_offset_days' => 1, 'sort' => 1]);

        return $template;
    }

    private function hire(string $code): Employee
    {
        return Employee::create(['employee_id' => $code, 'name' => "Joiner $code", 'gender' => 'Female', 'is_active' => true, 'date_of_joining' => now()->toDateString()]);
    }

    private function onboardingOf(Employee $employee): ?EmployeeChecklist
    {
        return EmployeeChecklist::query()->where('employee_id', $employee->getKey())->where('kind', ChecklistTemplate::KIND_ONBOARDING)->first();
    }

    public function test_the_only_active_template_starts_for_a_joiner(): void
    {
        $office = $this->template('Office');

        $this->assertSame($office->getKey(), $this->onboardingOf($this->hire('J-1'))?->checklist_template_id);
    }

    public function test_with_several_and_no_default_nothing_starts(): void
    {
        $this->template('Office');
        $this->template('Site');

        $this->assertNull($this->onboardingOf($this->hire('J-2')));
    }

    public function test_with_several_the_default_starts(): void
    {
        $this->template('Office');
        $site = $this->template('Site');
        app(TenantSettings::class)->set(ChecklistService::ONBOARDING_TEMPLATE_SETTING, $site->getKey());

        $this->assertSame($site->getKey(), $this->onboardingOf($this->hire('J-3'))?->checklist_template_id);
    }

    public function test_a_default_that_was_switched_off_is_not_used(): void
    {
        $this->template('Office');
        $this->template('Site');
        $retired = $this->template('Retired');
        $retired->update(['is_active' => false]);
        app(TenantSettings::class)->set(ChecklistService::ONBOARDING_TEMPLATE_SETTING, $retired->getKey());

        $this->assertNull($this->onboardingOf($this->hire('J-4')));
    }

    public function test_the_default_is_chosen_on_company_settings(): void
    {
        $this->template('Office');
        $site = $this->template('Site');

        Livewire::test(CompanySettings::class)
            ->fillForm(['lifecycle_onboarding_template_id' => $site->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($site->getKey(), (int) setting(ChecklistService::ONBOARDING_TEMPLATE_SETTING));
    }

    public function test_start_checklist_on_the_employee_starts_the_chosen_template_once(): void
    {
        $employee = $this->hire('J-5');           // no template yet, so nothing started
        $exit = $this->template('Leaving', ChecklistTemplate::KIND_EXIT);
        $office = $this->template('Office');

        Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
            ->callAction('startChecklist', ['template_id' => $office->getKey()])
            ->assertHasNoActionErrors()
            ->assertNotified('Office started');

        $this->assertSame($office->getKey(), $this->onboardingOf($employee)?->checklist_template_id);
        $this->assertSame(1, $this->onboardingOf($employee)->items()->count());

        // A second onboarding list while one runs is refused, with the reason.
        Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
            ->callAction('startChecklist', ['template_id' => $office->getKey()])
            ->assertNotified($employee->display_label.' already has an open onboarding checklist.');

        $this->assertSame(1, EmployeeChecklist::query()->where('employee_id', $employee->getKey())->count());

        // An exit list is a different kind, so it may run beside it.
        Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
            ->callAction('startChecklist', ['template_id' => $exit->getKey()])
            ->assertNotified('Leaving started');
    }
}
