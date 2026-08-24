<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Pages\FinalSettlementsReport;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Support\Reporting\ReportExport;
use App\Support\Reporting\ReportFigures;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Negatives in parentheses — `docs/reports-expansion-plan.md` Phase 4.3.
 *
 * "Accountants read `(1,250)`." A preference rather than a default, because `-1,250` is how everyone who is
 * not an accountant reads a figure and this application prints to both.
 *
 * The tests are mostly about what the rewrite must **not** touch, because that is where a regex over report
 * cells goes wrong: an em dash, a bare hyphen, a date, prose that happens to begin with a negative number,
 * and a figure that rounds away to nothing. All five appear in the numeric columns of reports already
 * shipped.
 *
 * And one property that matters more than any of them: **the CSV export does not get parentheses.** A
 * spreadsheet reads `(1,250)` as text, so the one file somebody opens in order to do arithmetic keeps the
 * minus sign. That is why the rewrite lives in the views rather than in the payload.
 */
class ReportFiguresTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['lifecycle', 'employees', 'leave', 'advances'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    private function parenthesise(bool $on = true): void
    {
        app(TenantSettings::class)->set('reports.negatives_in_parentheses', $on);
    }

    // ─────────────────────────────────── off by default ──

    /**
     * Nothing changes until a company asks for it.
     *
     * The safe default, and the reason it is a setting at all: turning this on for everybody would change
     * every figure on every report of every company that never asked.
     */
    public function test_the_preference_is_off_by_default(): void
    {
        $this->assertFalse(ReportFigures::parenthesised());
        $this->assertSame('-1,250', ReportFigures::money(-1250));
        $this->assertSame('-1,250', ReportFigures::cell('-1,250'));
    }

    /** And on when it is asked for. */
    public function test_the_preference_is_read_from_the_company(): void
    {
        $this->parenthesise();

        $this->assertTrue(ReportFigures::parenthesised());
        $this->assertSame('(1,250)', ReportFigures::money(-1250));
        $this->assertSame('(1,250)', ReportFigures::cell('-1,250'));
    }

    // ──────────────────────────────── raw numbers ──

    /** A positive figure is untouched either way. */
    public function test_a_positive_figure_is_never_parenthesised(): void
    {
        $this->parenthesise();

        $this->assertSame('1,250', ReportFigures::money(1250));
        $this->assertSame('0', ReportFigures::money(0));
    }

    /** Null is an empty cell, not a nought — a figure that does not apply is not a figure of zero. */
    public function test_null_is_empty_rather_than_nought(): void
    {
        $this->parenthesise();

        $this->assertSame('', ReportFigures::money(null));
        $this->assertSame('', ReportFigures::money(''));
    }

    /**
     * A figure that rounds away to nothing is not negative.
     *
     * `number_format(-0.4, 0)` is the string `-0`, so deciding the sign before the rounding gives `(0)` —
     * which reads as a puzzle — or `-0`, which reads as a bug. Both are wrong about the same number.
     */
    public function test_a_figure_that_rounds_to_nothing_is_not_negative(): void
    {
        $this->parenthesise();

        $this->assertSame('0', ReportFigures::money(-0.4));
        $this->assertSame('0', ReportFigures::money(-0.0));
    }

    /** Decimals are honoured where a report asks for them. */
    public function test_decimals_are_honoured(): void
    {
        $this->parenthesise();

        $this->assertSame('(1,250.50)', ReportFigures::money(-1250.5, 2));
        $this->assertSame('(0.40)', ReportFigures::money(-0.4, 2), 'at two places it no longer rounds away');
    }

    // ──────────────────── already-formatted cells, and what must survive ──

    /** A formatted negative is rewritten. */
    public function test_a_formatted_negative_cell_is_rewritten(): void
    {
        $this->parenthesise();

        $this->assertSame('(1,250)', ReportFigures::cell('-1,250'));
        $this->assertSame('(1250)', ReportFigures::cell('-1250'));
        $this->assertSame('(1,250.50)', ReportFigures::cell('-1,250.50'));
    }

    /** A negative percentage too — a rate that fell is read the same way a figure that fell is. */
    public function test_a_negative_percentage_is_rewritten(): void
    {
        $this->parenthesise();

        $this->assertSame('(5.2%)', ReportFigures::cell('-5.2%'));
    }

    /**
     * An em dash survives, and this is the one that would break the most reports.
     *
     * Nearly every report in this application uses `—` for a figure that does not apply — the value of kit
     * nobody priced, the tenure of somebody still employed, a count of nought in a column of counts. It is
     * not a negative number and must come through untouched.
     */
    public function test_an_em_dash_survives(): void
    {
        $this->parenthesise();

        $this->assertSame('—', ReportFigures::cell('—'));
    }

    /** A bare hyphen survives too, for the same reason. */
    public function test_a_bare_hyphen_survives(): void
    {
        $this->parenthesise();

        $this->assertSame('-', ReportFigures::cell('-'));
    }

    /**
     * A date survives, and it appears in numeric columns.
     *
     * `2027-02-20` begins with digits and contains hyphens, and an unanchored pattern would have made a
     * hash of every date column on every report.
     */
    public function test_a_date_survives(): void
    {
        $this->parenthesise();

        $this->assertSame('2027-02-20', ReportFigures::cell('2027-02-20'));
    }

    /**
     * Prose that begins with a negative number survives.
     *
     * Footers say things like "-1,250 recovered" and a standing cell can read "-3 days". The pattern is
     * anchored at both ends so a cell that is *partly* a number is left alone entirely.
     */
    public function test_prose_beginning_with_a_negative_survives(): void
    {
        $this->parenthesise();

        $this->assertSame('-1,250 recovered', ReportFigures::cell('-1,250 recovered'));
        $this->assertSame('Draft · net differs', ReportFigures::cell('Draft · net differs'));
    }

    /** A formatted `-0` gets the same treatment `money()` gives it, so the two cannot disagree. */
    public function test_a_formatted_minus_nought_becomes_nought(): void
    {
        $this->parenthesise();

        $this->assertSame('0', ReportFigures::cell('-0'));
        $this->assertSame('0.00', ReportFigures::cell('-0.00'));
    }

    /** An empty cell stays empty. */
    public function test_an_empty_cell_stays_empty(): void
    {
        $this->parenthesise();

        $this->assertSame('', ReportFigures::cell(''));
    }

    // ────────────────── on a real report, and not in the CSV ──

    /** A leaver who owes the company money is a real negative on a real report. */
    private function negativeSettlement(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Danish',
            'date_of_joining' => '2022-01-01',
            'left_on' => '2026-11-30',
            'status' => 0,
        ]);

        FinalSettlement::create([
            'employee_id' => $employee->getKey(),
            'left_on' => '2026-11-30',
            'leave_encashment_amount' => 0,
            'gratuity_amount' => 0,
            'outstanding_advance' => 60_000,
            'net_amount' => -60_000,
            'status' => FinalSettlement::STATUS_DRAFT,
        ]);
    }

    /** The payload itself is unchanged — the rewrite is a display concern and lives in the views. */
    public function test_the_payload_still_carries_the_minus_sign(): void
    {
        $this->parenthesise();
        $this->negativeSettlement();

        $statement = Livewire::test(FinalSettlementsReport::class, ['asOf' => self::AS_OF])
            ->instance()
            ->statement();

        $net = $statement['rows'][0][array_search('Net', $statement['columns'], true)];

        $this->assertSame('-60,000', $net, 'the report states the figure; the view decides how to write it');
    }

    /**
     * And the CSV keeps the minus sign, whatever the company prefers.
     *
     * The property that matters most here. A spreadsheet reads `(60,000)` as text, so the one file somebody
     * opens in order to do arithmetic must not get parentheses — which is why the rewrite is in the views
     * and not in the payload every output shares.
     */
    public function test_the_csv_keeps_the_minus_sign_even_when_parentheses_are_on(): void
    {
        $this->parenthesise();
        $this->negativeSettlement();

        $statement = Livewire::test(FinalSettlementsReport::class, ['asOf' => self::AS_OF])
            ->instance()
            ->statement();

        $csv = app(ReportExport::class)->csv($statement);

        $this->assertStringContainsString('-60000', $csv);
        $this->assertStringNotContainsString('(60,000)', $csv);
        $this->assertStringNotContainsString("'(", $csv);
    }

    /** The report's own page renders the parentheses. */
    public function test_the_report_page_renders_parentheses_when_asked(): void
    {
        $this->parenthesise();
        $this->negativeSettlement();

        Livewire::test(FinalSettlementsReport::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertSee('(60,000)')
            ->assertDontSee('-60,000');
    }

    /** And renders the minus sign when not. */
    public function test_the_report_page_renders_a_minus_sign_by_default(): void
    {
        $this->negativeSettlement();

        Livewire::test(FinalSettlementsReport::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertSee('-60,000')
            ->assertDontSee('(60,000)');
    }

    /**
     * The em dashes on that same report still render, with the preference on.
     *
     * The regression this whole file exists to prevent: a rewrite that caught the dashes would empty half
     * the cells on half the reports, and it would look like missing data rather than like a formatting bug.
     */
    public function test_dashes_on_the_page_survive_the_preference(): void
    {
        $this->parenthesise();
        $this->negativeSettlement();

        Livewire::test(FinalSettlementsReport::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertSee('—');
    }
}
