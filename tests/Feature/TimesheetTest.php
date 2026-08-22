<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\BillableHours;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Timesheets and hours-based billing — docs/hrms-plan.md §4.3.
 *
 * The rate chain is what most of this file is about, and specifically its last rung:
 * time with no rate is **named and not billed**, never billed at a guess. An invoice
 * that looks right and charges the wrong amount is worse than one visibly missing forty
 * hours.
 */
class TimesheetTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['timesheets', 'employees', 'projects'] as $module) {
            $this->setModule($module, true);
        }

        $this->employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
        ]);

        $this->project = Project::create(['code' => 'P-1', 'name' => 'Migration']);
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function set(string $key, mixed $value): void
    {
        app(TenantSettings::class)->set($key, $value);
    }

    private function book(int $minutes, array $attributes = []): TimesheetEntry
    {
        return app(TimesheetService::class)->book(new TimesheetEntry(array_merge([
            'employee_id' => $this->employee->id,
            'project_id' => $this->project->id,
            'date' => '2026-08-14',
            'minutes' => $minutes,
        ], $attributes)));
    }

    // ---------------------------------------------------------------- booking

    public function test_time_is_booked_in_minutes(): void
    {
        $entry = $this->book(90);

        $this->assertSame(90, $entry->minutes);
        $this->assertSame(1.5, $entry->hours());
        $this->assertTrue($entry->is_billable);
    }

    /** More than a day is almost always hours typed where minutes were meant. */
    public function test_more_than_a_day_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than a day');

        $this->book(2000);
    }

    public function test_an_entry_with_no_time_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->book(0);
    }

    // ---------------------------------------------------------------- the rate chain

    public function test_the_project_rate_wins_over_the_employee_rate(): void
    {
        $this->employee->update(['hourly_rate' => 5000]);
        $this->project->update(['hourly_rate' => 8000]);

        $this->assertSame(8000.0, app(TimesheetService::class)->rateFor($this->employee, $this->project));
    }

    public function test_the_employee_rate_is_used_when_the_project_has_none(): void
    {
        $this->employee->update(['hourly_rate' => 5000]);

        $this->assertSame(5000.0, app(TimesheetService::class)->rateFor($this->employee, $this->project));
    }

    public function test_the_company_default_is_the_last_rung(): void
    {
        $this->set('timesheets.default_hourly_rate', 3000);

        $this->assertSame(3000.0, app(TimesheetService::class)->rateFor($this->employee, $this->project));
    }

    /**
     * The rung that matters: nothing says, so nothing is guessed.
     *
     * Returning a number here — any number — is how an invoice comes to look right and
     * charge the wrong amount.
     */
    public function test_no_rate_anywhere_returns_null_rather_than_a_guess(): void
    {
        $this->assertNull(app(TimesheetService::class)->rateFor($this->employee, $this->project));
    }

    // ---------------------------------------------------------------- billing

    public function test_billable_time_is_approved_billable_and_unbilled(): void
    {
        $approver = User::factory()->create(['status' => 1]);

        $approved = $this->book(120);
        app(TimesheetService::class)->approve($approved, $approver);

        // Not approved.
        $this->book(60, ['date' => '2026-08-13']);

        // Approved but not billable.
        $nonBillable = $this->book(60, ['date' => '2026-08-12', 'is_billable' => false]);
        app(TimesheetService::class)->approve($nonBillable, $approver);

        $billable = app(TimesheetService::class)->billableFor($this->project, 2026, 8);

        $this->assertCount(1, $billable);
        $this->assertSame(120, $billable->first()->minutes);
    }

    /** With approval not required, unapproved time is billable — both directions tested. */
    public function test_approval_can_be_waived(): void
    {
        $this->set('timesheets.require_approval_to_bill', false);
        $this->book(120);

        $this->assertCount(1, app(TimesheetService::class)->billableFor($this->project, 2026, 8));
    }

    public function test_locked_time_is_never_billed_twice(): void
    {
        $this->set('timesheets.require_approval_to_bill', false);
        $entry = $this->book(120);

        app(TimesheetService::class)->lock(collect([$entry]));

        $this->assertCount(0, app(TimesheetService::class)->billableFor($this->project, 2026, 8));
        $this->assertTrue($entry->fresh()->isLocked());
    }

    public function test_billed_time_can_no_longer_be_approved_or_edited(): void
    {
        $entry = $this->book(120);
        app(TimesheetService::class)->lock(collect([$entry]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been billed');

        app(TimesheetService::class)->approve($entry->fresh(), User::factory()->create(['status' => 1]));
    }

    // ---------------------------------------------------------------- invoice lines

    public function test_unpriced_time_is_named_and_not_billed(): void
    {
        $this->set('timesheets.require_approval_to_bill', false);
        $this->book(120);

        $run = $this->makeBillingRun();
        $priced = $this->priceRun($run);

        $this->assertSame([], $priced['lines'], 'Time with no rate must not reach an invoice.');
        $this->assertCount(1, $priced['unpriced']);
        $this->assertStringContainsString('no rate set', $priced['unpriced'][0]);
    }

    /** One line per person per project — forty two-hour lines is a document nobody reads. */
    public function test_hours_are_grouped_into_one_line_per_person_per_project(): void
    {
        $this->set('timesheets.require_approval_to_bill', false);
        $this->project->update(['hourly_rate' => 8000]);

        $this->book(120, ['date' => '2026-08-14']);
        $this->book(90, ['date' => '2026-08-13']);

        $priced = $this->priceRun($this->makeBillingRun());

        $this->assertCount(1, $priced['lines']);
        // 3.5 hours at 8,000.
        $this->assertSame(28000.0, $priced['lines'][0]['amount']);
        $this->assertStringContainsString('3.5 hours', $priced['lines'][0]['description']);
    }

    /** A project belonging to another client is not billed on this run. */
    public function test_only_projects_belonging_to_the_run_s_client_are_billed(): void
    {
        $this->set('timesheets.require_approval_to_bill', false);
        $this->project->update(['hourly_rate' => 8000]);
        $this->book(120);

        $run = $this->makeBillingRun();

        // The project is moved to a different client — a real one, so the foreign key
        // holds and the test exercises the filter rather than the constraint.
        $other = \App\Modules\Invoicing\Models\Contact::create([
            'name' => 'Another client',
            'kind' => \App\Modules\Invoicing\Models\Contact::KIND_CUSTOMER,
        ]);
        $this->project->update(['contact_id' => $other->id]);

        $this->assertSame([], $this->priceRun($run)['lines']);
    }

    // ---------------------------------------------------------------- degradation

    /**
     * Without `attendance` the utilisation comparison says so rather than failing.
     *
     * There is nothing to compare booked time against, and that is a sentence rather
     * than an error.
     */
    public function test_utilisation_reports_that_it_cannot_compare_without_attendance(): void
    {
        $this->setModule('attendance', false);
        $this->book(480);

        $utilisation = app(TimesheetService::class)->utilisationFor($this->employee, 2026, 8);

        $this->assertSame(8.0, $utilisation['booked_hours']);
        $this->assertStringContainsString('not licensed', $utilisation['note']);
    }

    /** Allocation against booked time, which is the report this module is bought for. */
    public function test_plan_versus_actual_compares_allocation_with_booked_time(): void
    {
        $this->project->employees()->attach($this->employee->id, [
            'allocation_pct' => 50,
            'from_date' => '2026-08-01',
        ]);

        $this->book(480);

        $rows = app(TimesheetService::class)->planVersusActual($this->employee, 2026, 8);

        $this->assertCount(1, $rows);
        $this->assertSame('Migration', $rows[0]['project']);
        $this->assertSame(50.0, $rows[0]['allocation_pct']);
        $this->assertSame(8.0, $rows[0]['booked_hours']);
    }

    /**
     * Price a run's month the way Billing does — by handing over the contact, year and month.
     *
     * `priceFor()` used to take the `BillingRun` itself, which was the `timesheets -> billing` half of that
     * cycle. See App\Support\Contracts\BillableTime.
     *
     * @return array{lines: array<int, array<string, mixed>>, entries: mixed, unpriced: array<int, string>}
     */
    private function priceRun(\App\Modules\Billing\Models\BillingRun $run): array
    {
        $start = $run->periodStart();

        return app(BillableHours::class)->priceFor($run->contact_id, $start->year, $start->month);
    }

    private function makeBillingRun(): \App\Modules\Billing\Models\BillingRun
    {
        $contact = \App\Modules\Invoicing\Models\Contact::create([
            'name' => 'Acme GmbH',
            'kind' => \App\Modules\Invoicing\Models\Contact::KIND_CUSTOMER,
        ]);

        $this->project->update(['contact_id' => $contact->id]);

        $fiscalYear = \App\Modules\Core\Models\FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30'],
        );

        return \App\Modules\Billing\Models\BillingRun::create([
            'contact_id' => $contact->id,
            'month' => 'August',
            'fiscal_year_id' => $fiscalYear->id,
            'invoice_date' => '2026-09-01',
        ]);
    }
}
