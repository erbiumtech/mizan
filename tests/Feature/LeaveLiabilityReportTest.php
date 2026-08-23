<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Lifecycle\Filament\Pages\LeaveLiability as LeaveLiabilityPage;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Leave Liability — `docs/reports-expansion-plan.md` Phase 2.2.
 *
 * **The claim this file is built around is not a ledger tie**, because there is not one to make: nothing in
 * this application posts a leave provision, which is what Phase 2.2's own description says ("an accrual that
 * belongs in the accounts and is currently in nobody's figures"). So the first test asserts the report says
 * so, and the rest assert the thing that *can* be proved and matters just as much —
 *
 * **that the accrual equals what a final settlement would actually pay.** A liability computed its own way
 * would drift from the settlement it provides for, and the first person to leave would be paid an amount the
 * accrual never held. `LeaveBalanceTest` and the settlement tests own the arithmetic; what is asserted here
 * is that this report reuses it rather than restating it.
 */
class LeaveLiabilityReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'liability@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'leave', 'lifecycle'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function type(bool $encashable = true, array $attributes = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'code' => 'annual',
            'label' => 'Annual Leave',
            'days_per_year' => 14,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
            'is_encashable' => $encashable,
        ], $attributes));
    }

    /** An employee with a package, so there is a wage to compute a daily rate from. */
    private function employee(string $code, float $basic = 260_000, array $attributes = []): Employee
    {
        $employee = Employee::create(array_merge([
            'employee_id' => $code,
            'name' => $code,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ], $attributes));

        if ($basic > 0) {
            EmployeeSetting::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'basic_wage' => $basic,
            ]);
        }

        return $employee;
    }

    /** Days credited and none taken, so the whole entitlement is unused. */
    private function entitle(Employee $employee, LeaveType $type, float $days): LeaveEntitlement
    {
        return LeaveEntitlement::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2026-01-01',
            'leave_year_end' => '2026-12-31',
            'accrued_days' => $days,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('LeaveLiability', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for LeaveLiability');

        return $payload;
    }

    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    // ──────────────────────────────────────────── what it does and does not tie to ──

    /**
     * The report says the figure is in no account.
     *
     * Phase 2's rule is that each of its reports ties to a ledger balance, and this is the one that cannot:
     * there is no leave-liability account and nothing posts one. Saying so is the report's value, so it is
     * on the face of it and asserted here rather than left to the help.
     */
    public function test_it_says_the_liability_is_posted_nowhere(): void
    {
        $type = $this->type();
        $this->entitle($this->employee('EMP-1'), $type, 10);

        $this->assertStringContainsString('NOT POSTED TO ANY ACCOUNT', $this->report()['note']);
    }

    /**
     * The accrual equals what a settlement would pay, per employee and in total.
     *
     * The claim that replaces the ledger tie. Asked of `FinalSettlementBuilder` directly and compared with
     * what the report states: if the report ever grew a formula of its own, this is what would fail.
     */
    public function test_the_liability_equals_what_a_settlement_would_pay(): void
    {
        $type = $this->type();
        $one = $this->employee('EMP-1', 260_000);
        $two = $this->employee('EMP-2', 130_000);

        $this->entitle($one, $type, 10);
        $this->entitle($two, $type, 4);

        $builder = app(FinalSettlementBuilder::class);
        $expected = round(
            $builder->leaveEncashment($one, Carbon::parse(self::AS_OF))['amount']
            + $builder->leaveEncashment($two, Carbon::parse(self::AS_OF))['amount'],
            2,
        );

        $payload = $this->report();

        $this->assertGreaterThan(0.0, $expected, 'the fixture should produce a liability');
        $this->assertSame($expected, $payload['tiles'][0]['value']);
        $this->assertSame(14.0, $payload['tiles'][1]['value'], 'ten days plus four');
    }

    /** And the daily rate shown is the one the amount was computed at. */
    public function test_the_daily_rate_shown_reproduces_the_amount(): void
    {
        $type = $this->type();
        // 260,000 over the default divisor of 26 is 10,000 a day.
        $this->entitle($this->employee('EMP-1', 260_000), $type, 10);

        $payload = $this->report();
        $row = $payload['rows'][0];

        $this->assertSame('10.0', $row[1]);
        $this->assertSame('10,000', $row[2]);
        $this->assertSame('100,000', $row[3]);
    }

    /** The divisor is a company setting, and the report follows it rather than assuming 26. */
    public function test_it_follows_the_companys_encashment_divisor(): void
    {
        $type = $this->type();
        $this->entitle($this->employee('EMP-1', 300_000), $type, 10);

        $atTwentySix = $this->report()['tiles'][0]['value'];

        app(\App\Support\TenantSettings::class)->set('statutory.encashment_divisor', 30);

        $atThirty = $this->report()['tiles'][0]['value'];

        $this->assertGreaterThan($atThirty, $atTwentySix, 'a bigger divisor is a smaller daily rate');
        $this->assertSame(100_000.0, $atThirty, '300,000 over 30 is 10,000 a day, ten days');
    }

    // ─────────────────────────────────────────────── what counts as a liability ──

    /** Only encashable types. Leave that lapses costs nobody anything. */
    public function test_leave_that_lapses_is_not_a_liability(): void
    {
        $this->entitle($this->employee('EMP-1'), $this->type(encashable: false), 10);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO LEAVE TYPE IS ENCASHABLE, SO UNUSED LEAVE LAPSES', $payload['note']);
    }

    /**
     * And "nothing is encashable" is a different answer from "nobody has any left".
     *
     * A nought against both would read as a company that happens to be up to date, when in one case there
     * is nothing to be up to date about.
     */
    public function test_nothing_encashable_reads_differently_from_nobody_owed(): void
    {
        $lapsing = $this->report();
        $this->assertStringContainsString('NO LEAVE TYPE IS ENCASHABLE', $lapsing['note']);

        $this->type();

        $withNobody = $this->report();
        $this->assertStringContainsString('NOBODY HAS UNUSED', $withNobody['note']);
        $this->assertStringContainsString('NOT POSTED TO ANY ACCOUNT', $withNobody['note']);
    }

    /** Somebody who has left is not a provision — they are a payable or a debt. */
    public function test_a_leaver_is_not_a_liability(): void
    {
        $type = $this->type();
        $gone = $this->employee('EMP-1', 260_000, ['left_on' => '2026-07-31', 'is_active' => false]);
        $this->entitle($gone, $type, 10);

        $this->assertSame([], $this->report()['rows']);
    }

    /** But somebody leaving after the date still is, as at that date. */
    public function test_somebody_leaving_later_is_still_a_liability_today(): void
    {
        $type = $this->type();
        $leaving = $this->employee('EMP-1', 260_000, ['left_on' => '2026-09-30']);
        $this->entitle($leaving, $type, 10);

        $this->assertCount(1, $this->report()['rows']);
    }

    /** Nobody with nothing owed clutters the list. */
    public function test_people_with_no_balance_are_left_off(): void
    {
        $type = $this->type();
        $this->entitle($this->employee('EMP-1'), $type, 10);
        $this->employee('EMP-2');

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('EMP-1', $this->cells($payload));
        $this->assertStringNotContainsString('EMP-2', $this->cells($payload));
        $this->assertStringContainsString('1 EMPLOYEES', $payload['note']);
    }

    /** Days taken reduce the liability, because the balance is what is left. */
    public function test_days_taken_reduce_the_liability(): void
    {
        $type = $this->type();
        $employee = $this->employee('EMP-1', 260_000);
        $entitlement = $this->entitle($employee, $type, 10);

        $before = $this->report()['tiles'][0]['value'];

        // An adjustment, which is the balance's own arithmetic rather than a leave request this test would
        // have to approve — the point is that the report follows the balance, whatever moved it.
        $entitlement->adjustments()->create(['days' => -4, 'reason' => 'Correction']);

        $after = $this->report()['tiles'][0]['value'];

        $this->assertSame(40_000.0, round($before - $after, 2), 'four days at 10,000 a day');
    }

    /**
     * Somebody with no recorded wage owes days that cannot be priced, and both money cells say so.
     *
     * A nought there would claim the days are worth nothing. They are worth an amount nobody has recorded
     * the wage to compute — a data problem, not a liability of nil — so the row reads as unknown and the
     * note says the total is incomplete.
     */
    public function test_days_that_cannot_be_priced_read_as_unknown_rather_than_nought(): void
    {
        $type = $this->type();
        $this->entitle($this->employee('EMP-1', 0), $type, 10);

        $payload = $this->report();

        $this->assertSame('10.0', $payload['rows'][0][1]);
        $this->assertSame('—', $payload['rows'][0][2], 'the rate is unknown, not nought');
        $this->assertSame('—', $payload['rows'][0][3], 'the amount is unknown, not nought');
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
        $this->assertStringContainsString('1 HAS NO RECORDED WAGE, SO THE TOTAL IS INCOMPLETE', $payload['note']);
    }

    /** And with everybody priced, the note makes no such claim. */
    public function test_a_fully_priced_report_does_not_claim_to_be_incomplete(): void
    {
        $this->entitle($this->employee('EMP-1', 260_000), $this->type(), 10);

        $this->assertStringNotContainsString('INCOMPLETE', $this->report()['note']);
    }

    /** The record row foots the rows above it. */
    public function test_the_record_row_foots_the_rows(): void
    {
        $type = $this->type();
        $this->entitle($this->employee('EMP-1', 260_000), $type, 10);
        $this->entitle($this->employee('EMP-2', 130_000), $type, 6);

        $payload = $this->report();

        foreach ([1, 3] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace(',', '', $row[$column]),
                $payload['rows'],
            ));

            $this->assertLessThanOrEqual(
                count($payload['rows']),
                abs((float) str_replace(',', '', $payload['footer'][$column]) - $rows),
                "column {$column} does not add up the rows above it",
            );
        }
    }

    /** Without the leave module there is no encashable leave, and the report says so rather than failing. */
    public function test_without_the_leave_module_it_says_there_is_nothing_encashable(): void
    {
        $type = $this->type();
        $this->entitle($this->employee('EMP-1', 260_000), $type, 10);

        $this->setModule('leave', false);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO LEAVE TYPE IS ENCASHABLE, SO UNUSED LEAVE LAPSES', $payload['note']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->entitle($this->employee('EMP-1', 260_000), $this->type(), 10);

        $onThePage = Livewire::test(LeaveLiabilityPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('LeaveLiability', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(LeaveLiabilityPage::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(LeaveLiabilityPage::canAccess());
    }
}
