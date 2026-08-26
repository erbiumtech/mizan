<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Support\Reporting\ReportComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Comparison bases — `docs/reports-expansion-plan.md` Phase 4.2.
 *
 * "Comparison periods beyond the previous year — previous month, previous quarter, budget."
 *
 * The tests concentrate on the one thing that makes this more than a picker: **a basis shorter than the
 * reporting period narrows the current period too.** A profit and loss is the financial year to date, and
 * comparing it against "the previous month" by shifting its range back thirty days would put two
 * overlapping eight-month spans side by side — a figure that looks plausible and means nothing. So the basis
 * decides the length of both columns.
 *
 * A balance sheet is exempt and that is not an inconsistency: it is an as-at, so the current figure is the
 * balance on the day whatever the basis, and only the comparison date moves.
 *
 * There are also tests for the legacy `?comparison=` links, because people keep URLs and a bookmark that
 * said "no comparison" must still mean it.
 */
class ReportComparisonTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2025-2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );
    }

    // ────────────────────────────── what the basis may be ──

    /** Four bases, widest first — the previous year is what a statement is normally read against. */
    public function test_the_bases_are_offered_widest_first(): void
    {
        $this->assertSame([
            ReportComparison::PREVIOUS_YEAR,
            ReportComparison::PREVIOUS_QUARTER,
            ReportComparison::PREVIOUS_MONTH,
            ReportComparison::NONE,
        ], array_keys(ReportComparison::BASES));
    }

    /**
     * Anything unrecognised becomes the previous year, not no comparison.
     *
     * The commonest way to arrive with a bad basis is an old or hand-edited link, and answering that with a
     * column silently removed is worse than answering it with the conventional one.
     */
    public function test_an_unknown_basis_becomes_the_previous_year(): void
    {
        foreach ([null, '', 'previous_fortnight', 'budget', '../etc/passwd'] as $bad) {
            $this->assertSame(ReportComparison::PREVIOUS_YEAR, ReportComparison::normalise($bad));
        }
    }

    /** A known basis survives normalisation, including "none". */
    public function test_a_known_basis_survives(): void
    {
        $this->assertSame(ReportComparison::NONE, ReportComparison::normalise('none'));
        $this->assertSame(ReportComparison::PREVIOUS_MONTH, ReportComparison::normalise('previous_month'));
    }

    /**
     * Budget is deliberately not a basis.
     *
     * `BudgetVsActual` already is that report — per account, Planned against Actual with the variance, and
     * its own budget picker. A second implementation in the comparison slot would be a poorer one, since a
     * statement has nowhere to ask which budget, and two paths to one comparison is how they come to
     * disagree.
     */
    public function test_budget_is_not_a_comparison_basis(): void
    {
        $this->assertArrayNotHasKey('budget', ReportComparison::BASES);
        $this->assertSame(ReportComparison::PREVIOUS_YEAR, ReportComparison::normalise('budget'));
    }

    // ─────────────────────────── as-at shifts, for a balance sheet ──

    /** Each basis shifts an as-at date by its own span. */
    public function test_each_basis_shifts_an_as_at_date(): void
    {
        $this->assertSame('2025-02-20', ReportComparison::shift(ReportComparison::PREVIOUS_YEAR, '2026-02-20'));
        $this->assertSame('2025-11-20', ReportComparison::shift(ReportComparison::PREVIOUS_QUARTER, '2026-02-20'));
        $this->assertSame('2026-01-20', ReportComparison::shift(ReportComparison::PREVIOUS_MONTH, '2026-02-20'));
        $this->assertNull(ReportComparison::shift(ReportComparison::NONE, '2026-02-20'));
    }

    /**
     * The shift does not overflow into the following month.
     *
     * Plain `subMonth()` from 31 March lands on 3 March, because February has no 31st. Which would make a
     * month-on-month balance sheet at any month end quietly compare against the wrong date.
     */
    public function test_a_month_end_shift_does_not_overflow(): void
    {
        $this->assertSame('2026-02-28', ReportComparison::shift(ReportComparison::PREVIOUS_MONTH, '2026-03-31'));

        // 31 May, not 31 March: three months back from March is December, which *has* a 31st, so that date
        // cannot tell the two subtractions apart. Three months back from May is February, which cannot. A
        // surviving mutation is what pointed the difference out.
        $this->assertSame('2026-02-28', ReportComparison::shift(ReportComparison::PREVIOUS_QUARTER, '2026-05-31'));
    }

    // ────────────────── period ranges, and the narrowing that matters ──

    /**
     * The previous year leaves the current period as the financial year to date.
     *
     * Which is what these statements have always shown — 1 July, not 1 January.
     */
    public function test_the_previous_year_keeps_the_financial_year_to_date(): void
    {
        $this->assertSame(
            ['from' => '2025-07-01', 'to' => '2026-02-20'],
            ReportComparison::currentRange(ReportComparison::PREVIOUS_YEAR, '2026-02-20'),
        );
        $this->assertSame(
            ['from' => '2024-07-01', 'to' => '2025-02-20'],
            ReportComparison::previousRange(ReportComparison::PREVIOUS_YEAR, '2026-02-20'),
        );
    }

    /** No comparison leaves the period alone too, and has no second range. */
    public function test_no_comparison_keeps_the_period_and_has_no_previous(): void
    {
        $this->assertSame(
            ['from' => '2025-07-01', 'to' => '2026-02-20'],
            ReportComparison::currentRange(ReportComparison::NONE, '2026-02-20'),
        );
        $this->assertNull(ReportComparison::previousRange(ReportComparison::NONE, '2026-02-20'));
    }

    /**
     * A month basis narrows the current period to that month. **This is the phase.**
     *
     * Without it the comparison column would be a year-to-date shifted by thirty days, whose difference
     * from the current year-to-date is almost entirely the same trading counted twice.
     */
    public function test_a_month_basis_narrows_the_current_period(): void
    {
        $this->assertSame(
            ['from' => '2026-02-01', 'to' => '2026-02-20'],
            ReportComparison::currentRange(ReportComparison::PREVIOUS_MONTH, '2026-02-20'),
        );
    }

    /**
     * And compares it against the *whole* previous month.
     *
     * Twenty days against twenty days would be tidier and would answer a question nobody asks. What a month
     * is worth is what the month came to.
     */
    public function test_a_month_basis_compares_the_whole_previous_month(): void
    {
        $this->assertSame(
            ['from' => '2026-01-01', 'to' => '2026-01-31'],
            ReportComparison::previousRange(ReportComparison::PREVIOUS_MONTH, '2026-02-20'),
        );
    }

    /** A quarter is three calendar months ending in the one being read. */
    public function test_a_quarter_basis_takes_three_months_to_the_date(): void
    {
        $this->assertSame(
            ['from' => '2025-12-01', 'to' => '2026-02-20'],
            ReportComparison::currentRange(ReportComparison::PREVIOUS_QUARTER, '2026-02-20'),
        );
        $this->assertSame(
            ['from' => '2025-09-01', 'to' => '2025-11-30'],
            ReportComparison::previousRange(ReportComparison::PREVIOUS_QUARTER, '2026-02-20'),
        );
    }

    /** The two ranges never overlap, which is the property the narrowing exists to guarantee. */
    public function test_the_two_ranges_never_overlap(): void
    {
        foreach ([
            ReportComparison::PREVIOUS_YEAR,
            ReportComparison::PREVIOUS_QUARTER,
            ReportComparison::PREVIOUS_MONTH,
        ] as $basis) {
            $current = ReportComparison::currentRange($basis, '2026-02-20');
            $previous = ReportComparison::previousRange($basis, '2026-02-20');

            $this->assertLessThan(
                $current['from'],
                $previous['to'],
                "[{$basis}] the comparison period runs into the current one",
            );
        }
    }

    /** A month-end date does not overflow when the range is shifted either. */
    public function test_a_month_end_range_does_not_overflow(): void
    {
        $this->assertSame(
            ['from' => '2026-02-01', 'to' => '2026-02-28'],
            ReportComparison::previousRange(ReportComparison::PREVIOUS_MONTH, '2026-03-31'),
        );
    }

    // ─────────────────────────────── links people kept ──

    /**
     * `?comparison=0` still means no comparison.
     *
     * The hub carried a boolean before this phase and people keep links. Answering a saved "no comparison"
     * with the column back on would be a small betrayal of a bookmark.
     */
    public function test_a_legacy_comparison_flag_is_honoured(): void
    {
        $this->assertSame(ReportComparison::NONE, ReportComparison::fromLegacyFlag(false));
        $this->assertSame(ReportComparison::PREVIOUS_YEAR, ReportComparison::fromLegacyFlag(true));
    }

    /** And on the hub itself, where the translation actually happens. */
    public function test_the_hub_translates_a_legacy_flag(): void
    {
        $page = Livewire::test(Reports::class, ['comparison' => false, 'asOf' => '2026-02-20'])
            ->assertSuccessful()
            ->instance();

        $this->assertSame(ReportComparison::NONE, $page->comparisonBasis());
    }

    /** An explicit basis wins over the legacy flag, so a new link is not overridden by an old default. */
    public function test_an_explicit_basis_beats_the_legacy_flag(): void
    {
        $page = Livewire::test(Reports::class, [
            'comparison' => false,
            'compare' => ReportComparison::PREVIOUS_MONTH,
            'asOf' => '2026-02-20',
        ])->assertSuccessful()->instance();

        $this->assertSame(ReportComparison::PREVIOUS_MONTH, $page->comparisonBasis());
    }

    /** With neither, the previous year — as the hub has always defaulted. */
    public function test_the_hub_defaults_to_the_previous_year(): void
    {
        $page = Livewire::test(Reports::class, ['asOf' => '2026-02-20'])
            ->assertSuccessful()
            ->instance();

        $this->assertSame(ReportComparison::PREVIOUS_YEAR, $page->comparisonBasis());
    }

    /**
     * A basis set from the wire is normalised on read, not only in `mount()`.
     *
     * Livewire writes the property straight from the browser when the picker changes, so a value that never
     * passed through `mount()` would otherwise reach the statement unchecked.
     */
    public function test_a_basis_set_from_the_wire_is_normalised(): void
    {
        $page = Livewire::test(Reports::class, ['asOf' => '2026-02-20'])
            ->set('compare', 'nonsense')
            ->assertSuccessful()
            ->instance();

        $this->assertSame(ReportComparison::PREVIOUS_YEAR, $page->comparisonBasis());
    }
}
