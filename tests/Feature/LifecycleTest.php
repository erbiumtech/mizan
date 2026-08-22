<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Modules\Lifecycle\Services\ChecklistService;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Joining, leaving and the final settlement — docs/hrms-plan.md §4.6 and phases 5–6.
 *
 * The load-bearing assertion is the last section's: **a settlement is a proposal and
 * posts nothing.** Everything else here supports it — the asset register is what it
 * charges for, the leave balance is what it encashes, and both have to be right before
 * the figure means anything.
 */
class LifecycleTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['lifecycle', 'employees'] as $module) {
            $this->setModule($module, true);
        }

        $this->employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-06-01',
        ]);
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    // ---------------------------------------------------------------- checklists

    /**
     * Items are copied, not joined.
     *
     * The template may be edited or retired next year; a leaver's checklist must still
     * say what they were actually asked to do.
     */
    public function test_starting_a_checklist_copies_the_items(): void
    {
        $template = ChecklistTemplate::create(['kind' => ChecklistTemplate::KIND_ONBOARDING, 'name' => 'Standard']);
        $template->items()->createMany([
            ['title' => 'Order a laptop', 'owner_role' => 'IT', 'due_offset_days' => -7, 'sort' => 1],
            ['title' => 'Sign the contract', 'owner_role' => 'HR', 'due_offset_days' => 0, 'sort' => 2],
        ]);

        $checklist = app(ChecklistService::class)->start($this->employee, $template->fresh('items'), '2026-09-01');

        $this->assertCount(2, $checklist->items);
        $this->assertSame('2026-08-25', $checklist->items[0]->due_on->toDateString(), 'A -7 offset is a week before.');
        $this->assertSame('2026-09-01', $checklist->items[1]->due_on->toDateString());

        // Editing the template afterwards leaves the run alone.
        $template->items()->first()->update(['title' => 'Order two laptops']);
        $this->assertSame('Order a laptop', $checklist->fresh('items')->items[0]->title);
    }

    public function test_a_checklist_closes_when_its_last_item_is_done_and_reopens_with_it(): void
    {
        $template = ChecklistTemplate::create(['kind' => ChecklistTemplate::KIND_EXIT, 'name' => 'Exit']);
        $template->items()->create(['title' => 'Collect the laptop', 'sort' => 1]);

        $checklist = app(ChecklistService::class)->start($this->employee, $template->fresh('items'), '2026-09-01');
        $item = $checklist->items->first();

        app(ChecklistService::class)->complete($item, $this->actor);
        $this->assertNotNull($checklist->fresh()->completed_on);

        app(ChecklistService::class)->reopen($item->fresh());
        $this->assertNull($checklist->fresh()->completed_on, 'A run with an outstanding item is not complete.');
    }

    // ---------------------------------------------------------------- documents

    /**
     * Warn once per threshold, never once per day.
     *
     * The whole design. A daily repeat trains somebody to filter the warning, and then
     * the one that mattered is filtered too.
     */
    public function test_a_document_warns_once_per_threshold_crossed(): void
    {
        $document = EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'kind' => 'visa',
            'expires_on' => '2026-10-01',
        ]);

        $check = app(DocumentExpiryCheck::class);

        // 45 days out: inside 60, so it warns.
        $due = $check->due('2026-08-17');
        $this->assertCount(1, $due);
        $this->assertSame(60, $due[0]['threshold']);
        $check->markNotified($due[0]['document'], 60);

        // The next day is still inside 60 and must be silent.
        $this->assertCount(0, $check->due('2026-08-18'));

        // Crossing 30 is a new threshold, so it speaks again.
        $due = $check->due('2026-09-05');
        $this->assertCount(1, $due);
        $this->assertSame(30, $due[0]['threshold']);
    }

    /** A renewed document is a new deadline and must be able to warn again. */
    public function test_renewing_a_document_lets_it_warn_again(): void
    {
        $document = EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'kind' => 'passport',
            'expires_on' => '2026-09-01',
            'expiry_notified_at_days' => 7,
        ]);

        $this->assertCount(0, app(DocumentExpiryCheck::class)->due('2026-08-28'));

        app(DocumentExpiryCheck::class)->resetOnRenewal($document);
        $document->update(['expires_on' => '2026-10-15']);

        $this->assertCount(1, app(DocumentExpiryCheck::class)->due('2026-08-28'));
    }

    /** Expired is a different problem from expiring, and stays reported. */
    public function test_an_expired_document_reports_negative_days(): void
    {
        $document = EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'kind' => 'licence',
            'expires_on' => '2026-07-01',
        ]);

        $this->assertTrue($document->hasExpired('2026-08-10'));
        $this->assertSame(-40, $document->daysUntilExpiry('2026-08-10'));
    }

    /** A document with no expiry never warns and is not an error. */
    public function test_a_document_without_an_expiry_never_warns(): void
    {
        EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'kind' => 'degree',
        ]);

        $this->assertCount(0, app(DocumentExpiryCheck::class)->due('2026-08-10'));
    }

    // ---------------------------------------------------------------- settlement

    /**
     * THE test: a settlement gathers, and posts nothing.
     *
     * No journal entry, no payslip, no payment. §4.6 in one assertion.
     */
    public function test_building_a_settlement_posts_nothing(): void
    {
        $entries = \App\Modules\Accounting\Models\JournalEntry::count();
        $payslips = \App\Modules\Payroll\Models\Payslip::count();

        $this->givePackage(200000);
        app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');

        $this->assertSame($entries, \App\Modules\Accounting\Models\JournalEntry::count());
        $this->assertSame($payslips, \App\Modules\Payroll\Models\Payslip::count());

        $settlement = FinalSettlement::first();
        $this->assertSame(FinalSettlement::STATUS_DRAFT, $settlement->status);
        $this->assertNull($settlement->payslip_id);
        $this->assertNull($settlement->payment_id);
    }

    public function test_unreturned_kit_is_charged_and_returned_kit_is_not(): void
    {
        IssuedAsset::create([
            'employee_id' => $this->employee->id,
            'asset_kind' => 'laptop',
            'description' => 'MacBook Air',
            'issued_on' => '2024-01-01',
            'value' => 250000,
        ]);

        IssuedAsset::create([
            'employee_id' => $this->employee->id,
            'asset_kind' => 'phone',
            'description' => 'iPhone',
            'issued_on' => '2024-01-01',
            'returned_on' => '2026-08-30',
            'value' => 120000,
        ]);

        $this->givePackage(200000);
        $settlement = app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');

        $this->assertSame(250000.0, (float) $settlement->unreturned_asset_value);
    }

    /** Gratuity: one month per completed year, and nothing below the qualifying period. */
    public function test_gratuity_is_one_month_per_completed_year(): void
    {
        $this->givePackage(200000);

        // Joined 2020-06-01, leaving 2026-08-31 — six completed years.
        $settlement = app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');
        $this->assertSame(1200000.0, (float) $settlement->gratuity_amount);

        $newJoiner = Employee::create([
            'employee_id' => 'EMP-NEW',
            'name' => 'New',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2026-06-01',
        ]);
        $this->givePackage(100000, $newJoiner);

        $this->assertSame(
            0.0,
            (float) app(FinalSettlementBuilder::class)->build($newJoiner, '2026-08-31')->gratuity_amount,
            'Below the qualifying period, gratuity is nothing.',
        );
    }

    /** Encashable leave is valued and unencashable leave is not. */
    public function test_only_encashable_leave_types_are_paid_out(): void
    {
        $this->setModule('leave', true);
        $this->givePackage(260000); // 10,000 a day on the conventional 26-day divisor.

        $annual = LeaveType::create([
            'code' => 'annual', 'label' => 'Annual', 'days_per_year' => 14, 'is_encashable' => true,
        ]);
        $casual = LeaveType::create([
            'code' => 'casual', 'label' => 'Casual', 'days_per_year' => 10, 'is_encashable' => false,
        ]);

        foreach ([$annual, $casual] as $type) {
            LeaveEntitlement::create([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'leave_year_start' => '2026-01-01',
                'leave_year_end' => '2026-12-31',
                'accrued_days' => 5,
            ]);
        }

        $settlement = app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');

        $this->assertSame(5.0, (float) $settlement->leave_encashment_days, 'Only the encashable type.');
        $this->assertSame(50000.0, (float) $settlement->leave_encashment_amount);
    }

    /** Without `leave` there is no encashment, and that is not an error. */
    public function test_a_settlement_without_the_leave_module_encashes_nothing(): void
    {
        $this->setModule('leave', false);
        $this->givePackage(200000);

        $settlement = app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');

        $this->assertSame(0.0, (float) $settlement->leave_encashment_amount);
        $this->assertGreaterThan(0, (float) $settlement->gratuity_amount, 'The rest of the settlement still works.');
    }

    /**
     * An approved settlement is a figure somebody committed to.
     *
     * Rebuilding it from today's data would move what was agreed — an advance balance
     * changes as recoveries post.
     */
    public function test_an_approved_settlement_cannot_be_rebuilt(): void
    {
        $this->givePackage(200000);
        $settlement = app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');

        $settlement->update(['status' => FinalSettlement::STATUS_APPROVED]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been approved');

        app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');
    }

    /**
     * A settlement may be negative: somebody can owe the company on leaving.
     *
     * Shown as it falls rather than clamped, because clamping quietly writes off a debt
     * nobody decided to write off.
     */
    public function test_a_settlement_can_be_owed_to_the_company(): void
    {
        $newJoiner = Employee::create([
            'employee_id' => 'EMP-SHORT',
            'name' => 'Short service',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2026-06-01',
        ]);
        $this->givePackage(100000, $newJoiner);

        IssuedAsset::create([
            'employee_id' => $newJoiner->id,
            'asset_kind' => 'laptop',
            'description' => 'MacBook',
            'issued_on' => '2026-06-01',
            'value' => 250000,
        ]);

        $settlement = app(FinalSettlementBuilder::class)->build($newJoiner, '2026-08-31');

        $this->assertTrue($settlement->isOwedToCompany());
        $this->assertSame(-250000.0, $settlement->computedNet());
    }

    /** One settlement per employee: a double-clicked button must not make two. */
    public function test_building_twice_updates_rather_than_duplicates(): void
    {
        $this->givePackage(200000);

        app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');
        app(FinalSettlementBuilder::class)->build($this->employee, '2026-08-31');

        $this->assertSame(1, FinalSettlement::count());
    }

    private function givePackage(float $basic, ?Employee $employee = null): void
    {
        $fiscalYear = \App\Modules\Core\Models\FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30'],
        );

        EmployeeSetting::create([
            'employee_id' => ($employee ?? $this->employee)->id,
            'fiscal_year_id' => $fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => $basic,
        ]);
    }
}
