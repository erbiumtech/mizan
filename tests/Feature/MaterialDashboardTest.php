<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Widgets\RevenueAndExpensesChart;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Filament\Pages\Dashboard;
use App\Modules\Core\Filament\Widgets\DashboardSummaryCards;
use App\Modules\Core\Filament\Widgets\WaitingForYou;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\DashboardLayout;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Payroll\Filament\Widgets\CurrentPayrollRun;
use App\Support\ModuleMap;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The Material dashboard: a greeting, four summary cards, "Waiting for you" and the payroll run in progress.
 *
 * The three new pieces are ordinary dashboard widgets, so they live inside the layout system rather than
 * beside it — they lead the default order, a saved layout still decides, and each disappears with its module.
 * "Waiting for you" decides through the same queues and services as the `/v1` approvals endpoints, so these
 * tests check the rules those enforce (nobody is offered their own claim) reach the dashboard too.
 */
class MaterialDashboardTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** A Wednesday, so a one-day leave counts one working day whatever the weekend setting. */
    private const TODAY = '2026-11-04';

    private User $admin;

    private User $employeeUser;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');
        Notification::fake();

        $this->admin = $this->makeUser('Administrator', 'material-admin@test.local');
        $this->admin->forceFill(['name' => 'Ayesha Khan'])->save();
        $this->actingAs($this->admin);
        $company = $this->setCurrentTenant();

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->employeeUser = $this->makeUser('Employee', 'material-employee@test.local');
        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'name' => 'Sana Iqbal',
            'employee_id' => 'EMP-MD3',
            'gender' => 'Female',
            'phone' => '0300-0000030',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        $this->annual = LeaveType::create([
            'code' => 'annual', 'label' => 'Annual Leave', 'days_per_year' => 14,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
        ]);
        LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'leave_year_start' => Carbon::now()->startOfYear()->toDateString(),
            'leave_year_end' => Carbon::now()->endOfYear()->toDateString(),
            'accrued_days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────── the page ──

    public function test_the_page_greets_by_first_name(): void
    {
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Good morning, Ayesha');
    }

    public function test_the_default_layout_leads_with_the_new_widgets(): void
    {
        $widgets = $this->dashboardWidgets();

        $this->assertSame(
            [DashboardSummaryCards::class, WaitingForYou::class, CurrentPayrollRun::class],
            array_slice($widgets, 0, 3),
        );
        $this->assertContains(RevenueAndExpensesChart::class, $widgets, 'the older widgets are still there, below');
    }

    public function test_a_saved_layout_still_wins(): void
    {
        DashboardLayout::put($this->admin->id, ['order' => [
            ModuleMap::alias(RevenueAndExpensesChart::class),
            ModuleMap::alias(CurrentPayrollRun::class),
        ]]);

        $widgets = $this->dashboardWidgets();

        $this->assertSame([RevenueAndExpensesChart::class, CurrentPayrollRun::class], array_slice($widgets, 0, 2));
        $this->assertContains(DashboardSummaryCards::class, $widgets, 'an unmentioned widget still appears');
    }

    // ─────────────────────────────── summary cards ──

    public function test_the_summary_cards_show_figures_from_the_ledger_and_the_leave_book(): void
    {
        $leave = $this->pendingLeave(self::TODAY, self::TODAY);
        app(LeaveRequestService::class)->approve($leave, $this->admin);
        Account::query()->where('code', '1100')->update(['balance' => 250000]);

        Livewire::test(DashboardSummaryCards::class)
            ->assertOk()
            ->assertSeeInOrder(['Open work items', 'On leave today', '1', 'Invoices overdue', 'Cash in bank', '250,000']);
    }

    // ─────────────────────────────── waiting for you ──

    public function test_waiting_for_you_lists_decidable_leave_and_not_the_users_own_claim(): void
    {
        $this->pendingLeave('2026-11-09', '2026-11-10');

        $own = Employee::create([
            'user_id' => $this->admin->id,
            'name' => 'Ayesha Khan',
            'employee_id' => 'EMP-MD4',
            'gender' => 'Female',
            'phone' => '0300-0000031',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
        ExpenseClaim::create([
            'employee_id' => $own->id,
            'claimed_on' => '2026-11-02',
            'description' => 'My own lunch',
            'amount' => 900,
            'submitted_by' => $this->admin->id,
        ]);
        ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-11-02',
            'description' => 'Taxi to the client',
            'amount' => 1500,
            'submitted_by' => $this->employeeUser->id,
        ]);

        Livewire::test(WaitingForYou::class)
            ->assertOk()
            ->assertSee('Waiting for you')
            ->assertSee('Annual Leave')
            ->assertSee('Sana Iqbal')
            ->assertSee('Taxi to the client')
            ->assertDontSee('My own lunch');
    }

    public function test_approving_from_the_card_decides_and_removes_the_item(): void
    {
        $leave = $this->pendingLeave('2026-11-09', '2026-11-10');

        Livewire::test(WaitingForYou::class)
            ->assertSee('Sana Iqbal')
            ->callAction(TestAction::make('approve')->arguments(['key' => 'leave:'.$leave->getKey()]))
            // Nothing is mounted once the action has run, and a widget has no default form for
            // assertHasNoActionErrors() to fall back to; Livewire's own check says the same thing.
            ->assertHasNoErrors()
            ->assertNotified()
            ->assertDontSee('Sana Iqbal')
            ->assertSee('Nothing is waiting for you.');

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $leave->fresh()->status);
    }

    public function test_refusing_from_the_card_needs_a_reason(): void
    {
        $leave = $this->pendingLeave('2026-11-09', '2026-11-10');

        Livewire::test(WaitingForYou::class)
            ->callAction(TestAction::make('refuse')->arguments(['key' => 'leave:'.$leave->getKey()]), ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(LeaveRequest::STATUS_PENDING, $leave->fresh()->status);

        Livewire::test(WaitingForYou::class)
            ->callAction(TestAction::make('refuse')->arguments(['key' => 'leave:'.$leave->getKey()]), ['reason' => 'Audit week'])
            ->assertHasNoErrors()
            ->assertDontSee('Sana Iqbal');

        $this->assertSame(LeaveRequest::STATUS_REFUSED, $leave->fresh()->status);
    }

    // ─────────────────────────────── payroll ──

    public function test_the_payroll_card_says_when_no_run_is_in_progress(): void
    {
        Livewire::test(CurrentPayrollRun::class)
            ->assertOk()
            ->assertSee('No payroll run is in progress.');
    }

    // ─────────────────────────────── modules ──

    public function test_each_widget_hides_when_its_module_is_off(): void
    {
        $this->assertTrue(WaitingForYou::canView());
        $this->assertTrue(CurrentPayrollRun::canView());
        $this->assertTrue(DashboardSummaryCards::canView());

        $this->disable(['payroll']);
        $this->assertFalse(CurrentPayrollRun::canView());

        $this->disable(['leave', 'expenses']);
        $this->assertFalse(WaitingForYou::canView());

        $this->disable(['work', 'invoicing', 'accounting']);
        $this->assertFalse(DashboardSummaryCards::canView());

        $this->assertNotContains(WaitingForYou::class, $this->dashboardWidgets());
        $this->assertNotContains(CurrentPayrollRun::class, $this->dashboardWidgets());
    }

    // ─────────────────────────────── helpers ──

    private function pendingLeave(string $from, string $to): LeaveRequest
    {
        return app(LeaveRequestService::class)->submit(new LeaveRequest([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'from_date' => $from,
            'to_date' => $to,
            'reason' => 'Family wedding',
        ]), $this->employeeUser);
    }

    /** @param  array<int, string>  $modules */
    private function disable(array $modules): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', $modules)
            ->update(['enabled' => false]);

        modules()->flush();
    }

    /** @return array<int, string> the dashboard's widget classes, in the order it draws them */
    private function dashboardWidgets(): array
    {
        return array_map(
            fn ($widget): string => $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
            Livewire::test(Dashboard::class)->instance()->getWidgets(),
        );
    }
}
