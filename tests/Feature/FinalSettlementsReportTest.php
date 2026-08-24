<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Pages\FinalSettlementsReport;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Final settlements — `docs/reports-expansion-plan.md` Phase 3.8.
 *
 * A settlement posts nothing, so there is no ledger balance to reconcile against and the Phase 2 rule does
 * not apply. The report's value is three disagreements, and each has a test for the disagreement rather than
 * for the string that reports it:
 *
 *  - **a leaver nobody built a settlement for** — invisible on every other screen, because every other view
 *    of a settlement starts from one that exists;
 *  - **a stored net that is not the sum of its parts** — `net_amount` is written on build and on approve but
 *    not on edit, and every component is editable;
 *  - **a draft quoting kit that has since come back** — the tie to Phase 3.7, and deliberately *not* checked
 *    on approved settlements, whose figures are frozen by agreement.
 *
 * That last exclusion gets its own test in both directions. A report that flagged an approved settlement as
 * stale would be arguing with the agreement, which is the opposite of what the builder's refusal to rebuild
 * is there to protect.
 */
class FinalSettlementsReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** February of the 2026-2027 fiscal year, so the calendar year differs from the financial one. */
    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['lifecycle', 'employees', 'leave', 'advances', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        // Without this the period falls back to `startOfYear()` — 1 January, not 1 July — which is
        // ReportPeriod's documented behaviour when a company has no fiscal year, and would quietly make every
        // period assertion in this file about the wrong span.
        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function leaver(string $name, string $leftOn = '2026-11-30'): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-'.mb_substr(md5($name), 0, 4),
            'name' => $name,
            'date_of_joining' => '2022-01-01',
            'left_on' => $leftOn,
            'status' => 0,
        ]);
    }

    /**
     * A settlement whose stored net agrees with its parts, unless a test deliberately says otherwise.
     *
     * Defaulting `net_amount` to the computed figure matters: it makes drift something a test has to *ask*
     * for, so a test that is not about drift cannot accidentally be about drift.
     */
    private function settlement(Employee $employee, array $attributes = []): FinalSettlement
    {
        $settlement = FinalSettlement::create(array_merge([
            'employee_id' => $employee->getKey(),
            'left_on' => $employee->left_on->toDateString(),
            'leave_encashment_days' => 10,
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 200_000,
            'status' => FinalSettlement::STATUS_DRAFT,
        ], $attributes));

        if (! array_key_exists('net_amount', $attributes)) {
            $settlement->update(['net_amount' => $settlement->computedNet()]);
        }

        return $settlement;
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('FinalSettlementsReport', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for FinalSettlementsReport');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ─────────────────────────────────────────────────────── the composition ──

    /** The plan asks for the composition, so every part of it is on the row. */
    public function test_the_composition_is_broken_into_its_parts(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 200_000,
            'notice_recovery' => 30_000,
            'outstanding_advance' => 40_000,
            'unreturned_asset_value' => 25_000,
            'other_deductions' => 5_000,
        ]);

        $payload = $this->report();

        $this->assertSame('50,000', $this->cell($payload, 'Encashment'));
        $this->assertSame('200,000', $this->cell($payload, 'Gratuity'));
        $this->assertSame('30,000', $this->cell($payload, 'Notice rec.'));
        $this->assertSame('40,000', $this->cell($payload, 'Advance'));
        $this->assertSame('25,000', $this->cell($payload, 'Kit'));
        $this->assertSame('5,000', $this->cell($payload, 'Other'));
    }

    /**
     * The net on the row is the sum of the parts on the row.
     *
     * 50,000 + 200,000 owed, less 30,000 + 40,000 + 25,000 + 5,000 owed back. Asserted as arithmetic rather
     * than as a stored column, because a row whose parts do not add up to its total reads as a mistake in the
     * report rather than a mistake in the record.
     */
    public function test_the_net_is_the_sum_of_the_parts_on_the_row(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 200_000,
            'notice_recovery' => 30_000,
            'outstanding_advance' => 40_000,
            'unreturned_asset_value' => 25_000,
            'other_deductions' => 5_000,
        ]);

        $payload = $this->report();

        $this->assertSame('150,000', $this->cell($payload, 'Net'));
        $this->assertSame(150_000.0, $payload['tiles'][0]['value']);
    }

    /** A zero component is a dash — ten columns of noughts is unreadable and the footer has the arithmetic. */
    public function test_a_zero_component_shows_a_dash(): void
    {
        $this->settlement($this->leaver('Ayesha'), ['notice_recovery' => 0, 'other_deductions' => 0]);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Notice rec.'));
        $this->assertSame('—', $this->cell($payload, 'Other'));
    }

    /**
     * A settlement the employee owes on is not netted against one the company owes.
     *
     * The model says a negative net is legitimate — an unrecovered advance and an unreturned laptop can leave
     * somebody owing. Summed into one figure the two cancel, and the result is neither what the company owes
     * nor what it is owed. Both are somebody's job, so both are their own tile.
     */
    public function test_what_is_owed_back_is_not_netted_against_what_is_payable(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 100_000,
            'gratuity_amount' => 0,
        ]);
        $this->settlement($this->leaver('Danish'), [
            'leave_encashment_amount' => 0,
            'gratuity_amount' => 0,
            'outstanding_advance' => 60_000,
        ]);

        $payload = $this->report();

        $this->assertSame(100_000.0, $payload['tiles'][0]['value'], 'payable');
        $this->assertSame(60_000.0, $payload['tiles'][1]['value'], 'owed back');
        $this->assertStringContainsString('100,000 PAYABLE', $payload['note']);
        $this->assertStringContainsString('60,000 OWED BACK TO THE COMPANY', $payload['note']);
    }

    /** The footer totals every component column, so the arithmetic can be checked down as well as across. */
    public function test_the_footer_totals_every_component(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 200_000,
            'outstanding_advance' => 10_000,
        ]);
        $this->settlement($this->leaver('Danish'), [
            'leave_encashment_amount' => 20_000,
            'gratuity_amount' => 100_000,
            'outstanding_advance' => 5_000,
        ]);

        $payload = $this->report();
        $at = fn (string $column): string => $payload['footer'][array_search($column, $payload['columns'], true)];

        $this->assertSame('70,000', $at('Encashment'));
        $this->assertSame('300,000', $at('Gratuity'));
        $this->assertSame('15,000', $at('Advance'));
        $this->assertSame('355,000', $at('Net'));
        $this->assertSame('Total — 2 leavers', $payload['footer'][0]);
    }

    // ──────────────────────────────────── a leaver with no settlement at all ──

    /**
     * Somebody left and nobody built their settlement.
     *
     * The reason the report lists leavers rather than settlements. Every other view of a settlement starts
     * from one that exists, so this employee appears on no screen in the application — which is exactly the
     * case somebody needs telling about.
     */
    public function test_a_leaver_with_no_settlement_is_listed_as_not_built(): void
    {
        $this->leaver('Ayesha');

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('Not built', $this->cell($payload, 'Status'));
        $this->assertSame('—', $this->cell($payload, 'Net'), 'nothing was computed, so nothing is claimed');
        $this->assertStringContainsString('1 HAS NO SETTLEMENT BUILT AT ALL', $payload['note']);
    }

    /** Somebody still employed is not a leaver and is not on the report. */
    public function test_a_current_employee_is_not_listed(): void
    {
        Employee::create([
            'employee_id' => 'EMP-CURR',
            'name' => 'Still Here',
            'date_of_joining' => '2022-01-01',
            'status' => 1,
        ]);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOBODY LEFT IN THIS PERIOD', $payload['note']);
    }

    /** A leaver who has a settlement is not also reported as missing one. */
    public function test_a_settled_leaver_is_not_reported_as_missing(): void
    {
        $this->settlement($this->leaver('Ayesha'));

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringNotContainsString('NO SETTLEMENT BUILT', $payload['note']);
    }

    /** Not built sorts first: it is the only row where the omission is total. */
    public function test_not_built_rows_are_listed_first(): void
    {
        // The settled leaver left later, so an ordering by date alone would put them first.
        $this->settlement($this->leaver('Ayesha', leftOn: '2027-01-31'));
        $this->leaver('Danish', leftOn: '2026-08-31');

        $payload = $this->report();

        $this->assertSame('Not built', $this->cell($payload, 'Status', 0));
        $this->assertStringContainsString('Danish', $this->cell($payload, 'Employee', 0));
    }

    /** Among equals, the most recent leaver is first — the order settlements get worked through. */
    public function test_the_most_recent_leaver_is_first(): void
    {
        $this->settlement($this->leaver('Older', leftOn: '2026-08-31'));
        $this->settlement($this->leaver('Newer', leftOn: '2027-01-31'));

        $payload = $this->report();

        $this->assertStringContainsString('Newer', $this->cell($payload, 'Employee', 0));
        $this->assertStringContainsString('Older', $this->cell($payload, 'Employee', 1));
    }

    // ──────────────────────────── a stored net that is not the sum of its parts ──

    /**
     * `net_amount` is written on build and on approve, and not on edit.
     *
     * Every component is editable on the resource form, so somebody typing a notice recovery into a draft
     * leaves the stored net behind. The figure of record then disagrees with the figures it is made of and
     * only one of them can be right — which nothing else in the application will ever mention.
     */
    public function test_a_stored_net_that_is_not_the_sum_of_its_parts_is_flagged(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 200_000,
            'notice_recovery' => 30_000,
            // What a build would have written before somebody typed the notice recovery in.
            'net_amount' => 250_000,
        ]);

        $payload = $this->report();

        $this->assertSame('Draft · net differs', $this->cell($payload, 'Status'));
        $this->assertSame('220,000', $this->cell($payload, 'Net'), 'the parts, which are what the row shows');
        $this->assertStringContainsString(
            '1 WHERE THE STORED NET IS NOT THE SUM OF ITS PARTS',
            $payload['note'],
        );
    }

    /** A settlement whose stored net agrees is not flagged. */
    public function test_a_settlement_that_agrees_with_itself_is_not_flagged(): void
    {
        $this->settlement($this->leaver('Ayesha'));

        $payload = $this->report();

        $this->assertSame('Draft', $this->cell($payload, 'Status'));
        $this->assertStringNotContainsString('NOT THE SUM OF ITS PARTS', $payload['note']);
    }

    /**
     * One paisa apart is a disagreement, and float subtraction hides it.
     *
     * 1234.56 minus 1234.55 is 0.009999999999990905 in binary floating point, so an `abs(...) >= 0.01`
     * tolerance reads a genuine one-paisa difference as *no difference* — which it did, until a surviving
     * mutation said the tolerance was doing nothing and it turned out to be doing the wrong thing. Four of
     * five sampled paisa-apart pairs failed the same way. The comparison rounds the difference instead.
     *
     * A real value here, not a contrived one: a settlement is money and the last paisa of it is the part
     * somebody queries.
     */
    public function test_a_one_paisa_disagreement_is_not_lost_to_floating_point(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 1234.56,
            'gratuity_amount' => 0,
            'net_amount' => 1234.55,
        ]);

        $this->assertSame('Draft · net differs', $this->cell($this->report(), 'Status'));
    }

    /** And figures that agree exactly are not reported as differing. */
    public function test_figures_that_agree_to_the_paisa_are_not_reported_as_differing(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'leave_encashment_amount' => 1234.56,
            'gratuity_amount' => 0,
            'net_amount' => 1234.56,
        ]);

        $this->assertSame('Draft', $this->cell($this->report(), 'Status'));
    }

    /** An approved settlement is checked too — the drift is in the record either way. */
    public function test_drift_is_reported_on_an_approved_settlement_as_well(): void
    {
        $this->settlement($this->leaver('Ayesha'), [
            'status' => FinalSettlement::STATUS_APPROVED,
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 0,
            'net_amount' => 90_000,
        ]);

        $this->assertSame('Approved · net differs', $this->cell($this->report(), 'Status'));
    }

    // ──────────────────────────────── a draft quoting kit that has moved ──

    /**
     * A draft still charging for a laptop that came back.
     *
     * The tie to Phase 3.7. The builder refuses to rebuild an *approved* settlement so the agreed figure
     * cannot move underneath it — but a draft has agreed nothing, and meanwhile kit comes back. A draft built
     * in January still deducting for a laptop returned in March is quietly wrong.
     */
    public function test_a_draft_quoting_kit_that_has_come_back_is_flagged(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->settlement($ayesha, ['unreturned_asset_value' => 180_000]);
        // Nothing outstanding: the laptop came back after the draft was built.

        $payload = $this->report();

        $this->assertSame('Draft · kit moved', $this->cell($payload, 'Status'));
        $this->assertStringContainsString('1 DRAFT QUOTING KIT THAT HAS SINCE MOVED', $payload['note']);
    }

    /** A draft whose kit figure still matches what is outstanding is not flagged. */
    public function test_a_draft_that_matches_what_is_outstanding_is_not_flagged(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->issue($ayesha, 180_000);
        $this->settlement($ayesha, ['unreturned_asset_value' => 180_000]);

        $payload = $this->report();

        $this->assertSame('Draft', $this->cell($payload, 'Status'));
        $this->assertStringNotContainsString('KIT THAT HAS SINCE MOVED', $payload['note']);
    }

    /** Kit issued *after* the draft was built moves the figure the other way, and is flagged too. */
    public function test_a_draft_missing_kit_that_is_still_out_is_flagged(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->issue($ayesha, 95_000);
        $this->settlement($ayesha, ['unreturned_asset_value' => 0]);

        $this->assertSame('Draft · kit moved', $this->cell($this->report(), 'Status'));
    }

    /**
     * An approved settlement is **not** checked against today's kit, and this is the point.
     *
     * Its figures are frozen by agreement — the builder refuses to rebuild it for exactly that reason. A
     * report that flagged it as stale would be arguing with the agreement rather than reporting a problem,
     * so the same data that flags a draft must leave an approved settlement alone.
     */
    public function test_an_approved_settlement_is_not_flagged_for_kit_that_has_moved(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->settlement($ayesha, [
            'status' => FinalSettlement::STATUS_APPROVED,
            'unreturned_asset_value' => 180_000,
        ]);

        $payload = $this->report();

        $this->assertSame('Approved', $this->cell($payload, 'Status'));
        $this->assertStringNotContainsString('KIT THAT HAS SINCE MOVED', $payload['note']);
    }

    /** Nor is a paid one, for the same reason and more so. */
    public function test_a_paid_settlement_is_not_flagged_for_kit_that_has_moved(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->settlement($ayesha, [
            'status' => FinalSettlement::STATUS_PAID,
            'unreturned_asset_value' => 180_000,
        ]);

        $this->assertSame('Paid', $this->cell($this->report(), 'Status'));
    }

    /** Kit that came back is not outstanding, so it does not count towards the comparison. */
    public function test_returned_kit_does_not_count_towards_what_is_outstanding(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->issue($ayesha, 180_000, returnedOn: '2027-01-10');
        $this->settlement($ayesha, ['unreturned_asset_value' => 180_000]);

        $this->assertSame('Draft · kit moved', $this->cell($this->report(), 'Status'));
    }

    /** Both findings on one settlement, both stated. */
    public function test_a_settlement_can_disagree_with_itself_in_two_ways_at_once(): void
    {
        $ayesha = $this->leaver('Ayesha');
        $this->settlement($ayesha, [
            'leave_encashment_amount' => 50_000,
            'gratuity_amount' => 0,
            'unreturned_asset_value' => 180_000,
            'net_amount' => 50_000,
        ]);

        $payload = $this->report();

        $this->assertSame('Draft · net differs · kit moved', $this->cell($payload, 'Status'));
        $this->assertStringContainsString('NOT THE SUM OF ITS PARTS', $payload['note']);
        $this->assertStringContainsString('KIT THAT HAS SINCE MOVED', $payload['note']);
    }

    private function issue(Employee $employee, float $value, ?string $returnedOn = null): IssuedAsset
    {
        return IssuedAsset::create([
            'employee_id' => $employee->getKey(),
            'asset_kind' => 'laptop',
            'description' => 'ThinkPad T14',
            'issued_on' => '2026-08-01',
            'returned_on' => $returnedOn,
            'value' => $value,
        ]);
    }

    // ────────────────────────────────────────────────────────── the period ──

    /** The period is the financial year to date, on the leaving date. */
    public function test_a_leaver_from_before_the_financial_year_is_not_listed(): void
    {
        $this->settlement($this->leaver('Ayesha', leftOn: '2026-06-30'));

        $this->assertSame([], $this->report()['rows']);
    }

    /** And one who leaves after the date being read has not left yet, as far as this read is concerned. */
    public function test_a_leaver_after_the_date_being_read_is_not_listed(): void
    {
        $this->settlement($this->leaver('Ayesha', leftOn: '2027-05-31'));

        $this->assertSame([], $this->report()['rows']);
    }

    /** An unsettled leaver is scoped to the same period, not to all of history. */
    public function test_an_unsettled_leaver_from_a_previous_year_is_not_listed(): void
    {
        $this->leaver('Ayesha', leftOn: '2025-11-30');

        $this->assertSame([], $this->report()['rows']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->settlement($this->leaver('Ayesha'));

        $onThePage = Livewire::test(FinalSettlementsReport::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('FinalSettlementsReport', self::AS_OF, false, []),
        );
    }

    /** Ten columns of money, so it scrolls rather than being silently clipped — Phase 0.2. */
    public function test_the_table_is_marked_wide(): void
    {
        $this->settlement($this->leaver('Ayesha'));

        $this->assertTrue($this->report()['wide']);
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(FinalSettlementsReport::canAccess());
    }
}
