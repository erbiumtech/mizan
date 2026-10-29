<?php

namespace Tests\Feature;

use App\Modules\Advances\Models\Advance;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\ChecklistItem;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Lifecycle\Models\EmploymentContract;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Modules\Lifecycle\Models\Separation;
use App\Modules\Lifecycle\Services\ChecklistService;
use App\Modules\Lifecycle\Services\EmploymentContracts;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use App\Modules\Lifecycle\Services\Separations;
use App\Modules\Payroll\Models\ProvidentFundEntry;
use App\Modules\Payroll\Services\ProvidentFund;
use App\Support\DocumentVerification;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Contracts, onboarding for everyone with auto-checks, separations with clearance,
 * and the login grace — docs/saas-launch-plan.md §14.5.
 */
class LifecycleDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $login;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);
        $this->actingAs($this->makeUser('Administrator', 'life-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'lifecycle', 'payroll', 'advances', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->login = $this->makeUser('Employee', 'life-emp@test.local');
        $this->employee = Employee::create(['user_id' => $this->login->id, 'employee_id' => 'EMP-L', 'name' => 'Laila', 'gender' => 'Female', 'is_active' => 1, 'date_of_joining' => '2026-11-01']);
    }

    // ───────────────────────────────────────────────────────────── contracts ──

    public function test_a_contract_is_activated_accepted_by_its_employee_with_time_and_address_and_filed_signed(): void
    {
        $contract = EmploymentContract::create(['employee_id' => $this->employee->id, 'type' => 'contract', 'starts_on' => '2026-11-01', 'ends_on' => '2027-10-31', 'gross' => 120000, 'probation_months' => 3]);
        $this->assertSame('CON-2026-0001', $contract->number);

        $service = app(EmploymentContracts::class);

        try {
            $service->accept($contract, $this->login, '10.0.0.1');
            $this->fail('A draft was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('active', $e->getMessage());
        }

        $service->activate($contract);
        $this->assertTrue($contract->fresh()->isActive());

        try {
            $service->accept($contract->fresh(), auth()->user(), '10.0.0.1');
            $this->fail('Somebody else accepted it.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('person it names', $e->getMessage());
        }

        $service->accept($contract->fresh(), $this->login, '10.0.0.1', ['Hassan Raza'], true);
        $fresh = $contract->fresh();
        $this->assertSame('2026-11-05 10:00:00', $fresh->accepted_at->toDateTimeString());
        $this->assertSame('10.0.0.1', $fresh->accepted_ip);
        $this->assertSame(['Hassan Raza'], $fresh->witnesses);
        $this->assertTrue($fresh->ip_disclosure);

        $document = $service->attachSignedScan($fresh, 'employee-documents/signed.pdf');
        $this->assertSame('contract', $document->kind);
        $this->assertSame('2027-10-31', $document->expires_on->toDateString(), 'the expiring report reads it');
        $this->assertSame($document->getKey(), (int) $fresh->fresh()->signed_document_id);

        // The QR answers against the live record.
        $this->assertContains(EmploymentContracts::KIND, DocumentVerification::kinds());
        $facts = $service->verificationFacts($fresh);
        $this->assertSame('CON-2026-0001', $facts['number']);
        $this->assertSame(120000.0, $facts['gross_monthly_salary']);

        // A second contract ends the first the day before it starts.
        $next = EmploymentContract::create(['employee_id' => $this->employee->id, 'type' => 'permanent', 'starts_on' => '2027-01-01', 'gross' => 150000]);
        $service->activate($next);
        $this->assertSame(EmploymentContract::STATUS_ENDED, $fresh->fresh()->status);
        $this->assertSame('2027-10-31', $fresh->fresh()->ends_on->toDateString(), 'a dated contract keeps its own end');

        $this->assertStringContainsString('CON-2026-0001', $service->renderPdf($fresh->fresh())->html());
    }

    // ──────────────────────────────────────────────────────────── onboarding ──

    public function test_onboarding_starts_for_every_new_employee_once_and_auto_items_close_from_the_records(): void
    {
        $template = ChecklistTemplate::create(['kind' => ChecklistTemplate::KIND_ONBOARDING, 'name' => 'Joining', 'is_active' => true]);
        $template->items()->createMany([
            ['title' => 'Login', 'kind' => ChecklistItem::KIND_AUTO, 'auto_check' => 'user_created', 'sort' => 1],
            ['title' => 'Package', 'kind' => ChecklistItem::KIND_AUTO, 'auto_check' => 'package_in_effect', 'sort' => 2],
            ['title' => 'Laptop', 'kind' => ChecklistItem::KIND_AUTO, 'auto_check' => 'asset_issued', 'sort' => 3],
            ['title' => 'Read the handbook', 'kind' => ChecklistItem::KIND_LINK, 'link_url' => 'https://intranet.test/handbook', 'sort' => 4],
            ['title' => 'Desk', 'kind' => ChecklistItem::KIND_MANUAL, 'sort' => 5],
        ]);

        $newcomer = Employee::create(['employee_id' => 'EMP-N', 'name' => 'Nadia', 'gender' => 'Female', 'is_active' => 1, 'date_of_joining' => '2026-11-05']);
        $this->assertSame(1, EmployeeChecklist::query()->where('employee_id', $newcomer->id)->count(), 'a plain creation starts onboarding');

        \App\Modules\Employees\Events\EmployeeJoined::dispatch($newcomer->fresh());
        $this->assertSame(1, EmployeeChecklist::query()->where('employee_id', $newcomer->id)->count(), 'a hire announcing the same person starts nothing twice');

        $checklist = EmployeeChecklist::query()->where('employee_id', $newcomer->id)->sole();
        $this->assertSame('https://intranet.test/handbook', $checklist->items()->where('kind', 'link')->first()->link_url);

        $this->assertSame(0, app(ChecklistService::class)->autoCheck($checklist), 'nothing true yet');

        $newcomer->update(['user_id' => $this->makeUser('Employee', 'nadia@test.local')->id]);
        IssuedAsset::create(['employee_id' => $newcomer->id, 'asset_kind' => 'laptop', 'description' => 'ThinkPad', 'issued_on' => '2026-11-05']);
        $this->assertSame(2, app(ChecklistService::class)->autoCheck($checklist->fresh()), 'login and laptop');
        $this->assertSame(3, $checklist->fresh()->outstandingCount());

        $desk = $checklist->items()->where('title', 'Desk')->first();
        app(ChecklistService::class)->waive($desk, auth()->user(), 'Works from home');
        $this->assertTrue($desk->fresh()->isDone());

        $this->artisan('lifecycle:run-checks', ['--tenant' => [$this->tenant->getKey()]])->assertSuccessful();
        $this->assertSame(2, $checklist->fresh()->outstandingCount(), 'package and handbook still open');
    }

    // ─────────────────────────────────────────────── separations and the grace ──

    public function test_a_separation_builds_its_clearance_from_the_records_and_closes_after_the_last_day(): void
    {
        IssuedAsset::create(['employee_id' => $this->employee->id, 'asset_kind' => 'phone', 'description' => 'iPhone', 'issued_on' => '2026-11-01', 'value' => 80000]);
        Advance::create(['employee_id' => $this->employee->id, 'total_amount' => 30000, 'monthly_instalment' => 10000, 'started_on' => '2026-10-01', 'status' => Advance::STATUS_ACTIVE]);
        app(ProvidentFund::class)->record($this->employee, ProvidentFundEntry::KIND_OPENING, 5000, 5000, 'Opening', '2026-10-01');
        ChecklistTemplate::create(['kind' => ChecklistTemplate::KIND_EXIT, 'name' => 'Exit', 'is_active' => true])->items()->create(['title' => 'Exit interview', 'sort' => 1]);

        $separation = app(Separations::class)->open($this->employee, 'resignation', '2026-11-30', '2026-11-01', 'Moving abroad');

        $this->assertSame(['Return Phone: iPhone', 'Recover the outstanding advance', 'Settle the provident fund balance', 'Exit interview'], $separation->items->pluck('title')->all());
        $this->assertSame(30000.0, (float) $separation->items[1]->amount);
        $this->assertSame(10000.0, (float) $separation->items[2]->amount);
        $this->assertFalse($separation->isCleared());
        $this->assertSame('2026-11-30', $this->employee->fresh()->left_on->toDateString(), 'the date at once');
        $this->assertTrue((bool) $this->employee->fresh()->is_active, 'but still on the books until the day after');

        try {
            app(Separations::class)->open($this->employee->fresh(), 'resignation', '2026-12-31');
            $this->fail('A second open separation.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already has an open separation', $e->getMessage());
        }

        foreach ($separation->items as $item) {
            $item->source === 'checklist' ? app(Separations::class)->waive($item, auth()->user(), 'Done by phone') : app(Separations::class)->clear($item, auth()->user());
        }
        $this->assertTrue($separation->fresh()->isCleared());
        $this->assertSame(['clear' => 4, 'total' => 4], $separation->fresh()->clearance());

        $settlement = app(FinalSettlementBuilder::class)->build($this->employee->fresh());
        $this->assertSame('2026-11-30', $settlement->left_on->toDateString(), 'the settlement reads the separation');

        // The login: open through the grace, closed after it.
        $this->assertTrue($this->login->canAccessTenant($this->tenant));
        Carbon::setTestNow('2026-12-07 10:00:00');
        $this->assertTrue($this->login->canAccessTenant($this->tenant), 'seven days of grace');
        Carbon::setTestNow('2026-12-08 10:00:00');
        $this->assertFalse($this->login->canAccessTenant($this->tenant));
        app(TenantSettings::class)->set('lifecycle.login_grace_days', 30);
        $this->assertTrue($this->login->canAccessTenant($this->tenant));

        $this->assertSame(1, app(Separations::class)->closeDue(Carbon::parse('2026-12-01')));
        $this->assertSame(Employee::STATUS_RESIGNED, $this->employee->fresh()->status);
        $this->assertFalse((bool) $this->employee->fresh()->is_active);
        $this->assertSame(Separation::STATUS_CLOSED, $separation->fresh()->status);
    }
}
