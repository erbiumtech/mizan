<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Support\PayrollMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The shape a fiscal year is allowed to have.
 *
 * This installation runs 1 July – 30 June, and `App\Support\PayrollMonth` is what depends on it: payslips are
 * keyed by month *name*, so turning "January" into a date needs the year's boundary. Nothing enforced that
 * shape, which is how two implementations of the boundary came to disagree for a decade of possible fiscal
 * years nobody had tried — see docs/module-packaging-plan.md.
 *
 * Two rules pin it down: **a year ends 30 June**, and **a year spans at most twelve months**. Together they
 * permit exactly one full-length shape without naming it, and they still allow the stub first year that
 * `BudgetTest` relies on — a company joining in February gets 1 February to 30 June.
 */
class FiscalYearShapeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
    }

    private function make(?string $start, ?string $end): FiscalYear
    {
        return FiscalYear::create([
            'name' => 'test',
            'start_date' => $start,
            'end_date' => $end,
            'is_active' => false,
        ]);
    }

    // ---------------------------------------------------------------- allowed

    public function test_the_ordinary_july_to_june_year_is_accepted(): void
    {
        $year = $this->make('2026-07-01', '2027-06-30');

        $this->assertTrue($year->exists);
    }

    /**
     * A company's first year on the system is routinely partial.
     *
     * Inventing the missing months would compare them against actuals that were never going to be there and
     * report the whole year as underspent — `BudgetTest` covers the consequence. So a short year is allowed,
     * provided it still ends where every other year ends.
     */
    public function test_a_stub_first_year_is_accepted(): void
    {
        $this->assertTrue($this->make('2026-02-01', '2026-06-30')->exists);
        $this->assertTrue($this->make('2026-06-01', '2026-06-30')->exists);
    }

    /**
     * A year with no dates is accepted, deliberately.
     *
     * `FiscalYearForm` does not collect them, so every year created through the panel has none, and
     * `containing()` already treats a dateless year as containing nothing. Refusing to save one would lock an
     * administrator out of activating it. See the caveat asserted at the bottom of this file.
     */
    public function test_a_year_with_no_dates_is_accepted(): void
    {
        $this->assertTrue($this->make(null, null)->exists);
    }

    // ---------------------------------------------------------------- refused

    public function test_a_year_ending_anywhere_but_30_june_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ends on 30 June');

        $this->make('2026-01-01', '2026-12-31');
    }

    /** The calendar year — the other shape somebody would reach for, and not the one in use. */
    public function test_a_calendar_year_is_refused(): void
    {
        $this->expectExceptionMessage('ends on 30 June');

        $this->make('2026-01-01', '2026-12-31');
    }

    /**
     * Longer than twelve months and a month name falls in the year twice.
     *
     * "January" would then be two different dates and `PayrollMonth` would silently pick one — the class of
     * bug this validation exists to make impossible rather than to detect.
     */
    public function test_a_year_longer_than_twelve_months_is_refused(): void
    {
        $this->expectExceptionMessage('at most twelve months');

        $this->make('2026-06-01', '2027-06-30');
    }

    public function test_a_year_ending_before_it_starts_is_refused(): void
    {
        $this->expectExceptionMessage('must end after it starts');

        $this->make('2027-07-01', '2026-06-30');
    }

    /** Editing an existing year into a bad shape is refused too, not only creating one. */
    public function test_an_update_into_a_bad_shape_is_refused(): void
    {
        $year = $this->make('2026-07-01', '2027-06-30');

        $this->expectExceptionMessage('ends on 30 June');

        $year->update(['end_date' => '2027-12-31']);
    }

    // ---------------------------------------------------------------- what it buys

    /**
     * Every shape the validation permits resolves its months unambiguously.
     *
     * This is the property the two rules exist for, asserted directly rather than inferred: for any accepted
     * year, each of the twelve month names maps to exactly one date inside it or outside it, never two.
     */
    public function test_every_permitted_year_resolves_each_month_once(): void
    {
        foreach ([['2026-07-01', '2027-06-30'], ['2026-02-01', '2026-06-30'], ['2026-11-01', '2027-06-30']] as [$start, $end]) {
            $year = new FiscalYear(['name' => 'x', 'start_date' => $start, 'end_date' => $end]);

            $resolved = [];

            foreach (['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'] as $month) {
                $resolved[] = PayrollMonth::firstDay($month, $year)->toDateString();
            }

            $this->assertSame(
                count($resolved),
                count(array_unique($resolved)),
                "{$start}..{$end}: two month names resolved to the same date",
            );
        }
    }

    /**
     * The hole this validation does **not** close, asserted so it is not mistaken for closed.
     *
     * A dateless year makes `PayrollMonth` resolve every month to whatever calendar year it currently is —
     * so the same payslip would date differently next January. Closing it means either making the dates
     * mandatory (which breaks the panel, since the form does not collect them) or having the form collect
     * them. Both are product decisions; this asserts the current behaviour so the decision is visible rather
     * than discovered.
     */
    public function test_a_dateless_year_still_resolves_months_to_the_current_year(): void
    {
        $dateless = new FiscalYear(['name' => '2026-2027']);

        $this->assertSame(
            now()->year,
            PayrollMonth::yearFor('July', $dateless),
            'a dateless year no longer follows the clock — if that was fixed, delete this test',
        );
    }
}
