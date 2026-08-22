<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Services\HolidayCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The company holiday calendar, which leave and attendance will both read.
 *
 * The API is what is under test rather than the screen: two modules asking the
 * same question in two different ways is how they come to disagree about a day,
 * and the leave-day generator will call this once per calendar day of a request.
 *
 * The sharpest case here is the last one. `is_recurring` is stored and computes
 * nothing — the plan is explicit that a recurring date is *marked*, never
 * generated, because Eid moves with the lunar calendar and a guessed date would
 * be wrong most years in a way nobody notices until payroll has run on it. A
 * later change that made the flag helpful would break that silently, so it is
 * asserted rather than assumed.
 */
class HolidayCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function calendar(): HolidayCalendar
    {
        return app(HolidayCalendar::class);
    }

    private function holiday(string $date, string $name = 'Public holiday', array $attributes = []): Holiday
    {
        return Holiday::create(array_merge([
            'date' => $date,
            'name' => $name,
        ], $attributes));
    }

    /**
     * Two rows for one day is not extra detail — it is a day counted twice by
     * anything that sums, and an answer that depends on read order for anything
     * that asks. The database refuses it rather than the form.
     */
    public function test_a_date_can_only_be_listed_once(): void
    {
        $this->holiday('2026-08-14', 'Independence Day');

        $this->expectException(QueryException::class);

        $this->holiday('2026-08-14', 'Independence Day (duplicate entry)');
    }

    public function test_is_holiday_answers_from_the_table(): void
    {
        $this->holiday('2026-08-14', 'Independence Day');

        $this->assertTrue($this->calendar()->isHoliday('2026-08-14'));
        $this->assertFalse($this->calendar()->isHoliday('2026-08-15'));
    }

    /** Callers hold dates either way — a form gives a string, a model gives Carbon. */
    public function test_is_holiday_accepts_a_carbon_as_well_as_a_string(): void
    {
        $this->holiday('2026-08-14', 'Independence Day');

        $this->assertTrue($this->calendar()->isHoliday(Carbon::parse('2026-08-14 16:30:00')));
        $this->assertFalse($this->calendar()->isHoliday(Carbon::parse('2026-08-15 16:30:00')));
    }

    /**
     * Both ends count.
     *
     * A leave request that runs to the 14th and a holiday on the 14th is the
     * commonest case this is asked about, and an exclusive upper bound would
     * charge that day to the employee's balance.
     */
    public function test_between_includes_both_ends(): void
    {
        $this->holiday('2026-08-10', 'Start of range');
        $this->holiday('2026-08-12', 'Inside the range');
        $this->holiday('2026-08-14', 'End of range');
        $this->holiday('2026-08-09', 'The day before');
        $this->holiday('2026-08-15', 'The day after');

        $found = $this->calendar()->between('2026-08-10', '2026-08-14');

        $this->assertSame(
            ['2026-08-10', '2026-08-12', '2026-08-14'],
            $found->map(fn (Holiday $holiday): string => $holiday->date->toDateString())->all(),
        );
    }

    public function test_between_returns_nothing_for_a_range_with_no_holidays_in_it(): void
    {
        $this->holiday('2026-08-14', 'Independence Day');

        $this->assertTrue($this->calendar()->between('2026-09-01', '2026-09-30')->isEmpty());
    }

    /** A single-day range is a range: from and to being equal must still match. */
    public function test_between_matches_a_range_of_one_day(): void
    {
        $this->holiday('2026-08-14', 'Independence Day');

        $this->assertCount(1, $this->calendar()->between('2026-08-14', '2026-08-14'));
    }

    /**
     * Strings, not models: the generator walks a request day by day and compares
     * each one against this, so unwrapping a model per iteration is the cost the
     * method exists to avoid.
     */
    public function test_dates_between_returns_y_m_d_strings(): void
    {
        $this->holiday('2026-08-14', 'Independence Day');
        $this->holiday('2026-08-19', 'Company shutdown');

        $dates = $this->calendar()->datesBetween('2026-08-01', '2026-08-31');

        $this->assertSame(['2026-08-14', '2026-08-19'], $dates);
        $this->assertContainsOnly('string', $dates);
    }

    /**
     * The calendar caches the table for the request, so a holiday added and then
     * asked about in the same request must not read as absent. That sequence is
     * the leave-day generator's, not a contrived one.
     */
    public function test_a_holiday_added_during_a_request_is_visible_immediately(): void
    {
        $calendar = $this->calendar();

        $this->assertFalse($calendar->isHoliday('2026-08-14'));

        $holiday = $this->holiday('2026-08-14', 'Independence Day');

        $this->assertTrue($calendar->isHoliday('2026-08-14'));

        $holiday->delete();

        $this->assertFalse($calendar->isHoliday('2026-08-14'));
    }

    public function test_the_date_and_recurring_flag_are_cast(): void
    {
        $holiday = $this->holiday('2026-08-14', 'Independence Day', ['is_recurring' => 1]);

        $holiday->refresh();

        $this->assertInstanceOf(Carbon::class, $holiday->date);
        $this->assertSame('2026-08-14', $holiday->date->toDateString());
        $this->assertTrue($holiday->is_recurring);
    }

    /**
     * `is_recurring` is a note for whoever builds next year's calendar, and
     * nothing else reads it.
     *
     * Marking Eid ul-Fitr recurring must not put a row on the same date next
     * year: Eid follows the lunar calendar and moves by roughly eleven days each
     * time, so the generated date would be wrong every year — and wrong in the
     * one place nobody re-checks, because it looks like it was entered on
     * purpose. Somebody confirms the date. See docs/hrms-plan.md §3.
     */
    public function test_a_recurring_holiday_is_marked_and_never_generated(): void
    {
        $this->holiday('2026-03-20', 'Eid ul-Fitr', ['is_recurring' => true]);

        $this->assertTrue($this->calendar()->isHoliday('2026-03-20'));

        // Next year's same calendar date: not a holiday, because nothing made it
        // one.
        $this->assertFalse($this->calendar()->isHoliday('2027-03-20'));

        // Nor anywhere else in the year after.
        $this->assertTrue($this->calendar()->between('2027-01-01', '2027-12-31')->isEmpty());

        $this->assertSame(1, Holiday::count());
    }
}
