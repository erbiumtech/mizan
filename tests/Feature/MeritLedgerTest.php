<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Services\MeritLedger;
use App\Modules\Performance\Support\PerformanceReports;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The merit-evidence ledger — docs/saas-launch-plan.md §8.1. Append-only; a
 * reversal is a new row pointing at the old; the balance is a sum; and
 * nothing here pre-fills a rating.
 */
class MeritLedgerTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['performance', 'employees', 'attendance'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->employee = Employee::create(['employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => true, 'date_of_joining' => '2020-01-01']);
    }

    private function ledger(): MeritLedger
    {
        return app(MeritLedger::class);
    }

    public function test_entries_are_append_only_and_a_reversal_is_a_new_row_pointing_at_the_old(): void
    {
        $credit = $this->ledger()->record($this->employee, 10, 'Perfect attendance in October', by: $this->actor, occurredOn: '2026-10-31');
        $debit = $this->ledger()->record($this->employee, -3, 'Missed the Monday report', occurredOn: '2026-11-03');

        $this->assertSame(10, $credit->points);
        $this->assertSame($this->actor->getKey(), (int) $credit->recorded_by);
        $this->assertNull($debit->recorded_by, 'A system entry has no recorder.');

        try {
            $credit->update(['points' => 99]);
            $this->fail('An entry was edited.');
        } catch (LogicException) {
        }

        try {
            $credit->delete();
            $this->fail('An entry was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame(10, $credit->fresh()->points);

        $reversal = $this->ledger()->reverse($debit, 'Excused: was on site that day', $this->actor);
        $this->assertSame(3, $reversal->points);
        $this->assertSame($debit->getKey(), (int) $reversal->reverses_entry_id);
        $this->assertSame(3, MeritEntry::count(), 'A reversal must be a row, not an edit.');

        // Once per entry, and never of a reversal.
        $this->expectException(InvalidArgumentException::class);
        $this->ledger()->reverse($debit, 'again', $this->actor);
    }

    public function test_the_balance_is_a_sum_over_the_period_and_zero_is_refused(): void
    {
        $this->ledger()->record($this->employee, 10, 'October', occurredOn: '2026-10-31');
        $this->ledger()->record($this->employee, -3, 'November', occurredOn: '2026-11-03');
        $this->ledger()->record($this->employee, 5, 'December', occurredOn: '2026-12-01');

        $this->assertSame(7, $this->ledger()->balance($this->employee, '2026-10-01', '2026-11-30'));
        $this->assertSame(12, $this->ledger()->balance($this->employee, '2026-01-01', '2026-12-31'));

        $summary = $this->ledger()->summary($this->employee, '2026-10-01', '2026-11-30');
        $this->assertSame(['credits' => 10, 'debits' => -3, 'balance' => 7], array_intersect_key($summary, array_flip(['credits', 'debits', 'balance'])));
        $this->assertCount(2, $summary['entries']);

        $this->expectException(InvalidArgumentException::class);
        $this->ledger()->record($this->employee, 0, 'nothing');
    }

    public function test_the_review_shows_the_ledger_for_its_period_and_never_writes_a_rating(): void
    {
        $cycle = ReviewCycle::create(['name' => 'H2 2026', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31', 'status' => ReviewCycle::STATUS_OPEN]);
        $review = Review::create(['review_cycle_id' => $cycle->getKey(), 'employee_id' => $this->employee->getKey(), 'status' => Review::STATUS_PENDING]);

        $this->ledger()->record($this->employee, 10, 'October', occurredOn: '2026-10-31');
        $this->ledger()->record($this->employee, -3, 'November', occurredOn: '2026-11-03');
        $this->ledger()->record($this->employee, 50, 'Outside the cycle', occurredOn: '2027-01-15');

        $summary = $this->ledger()->summary($review->employee_id, $cycle->period_start->toDateString(), $cycle->period_end->toDateString());
        $this->assertSame(7, $summary['balance']);

        $this->assertNull($review->fresh()->manager_rating);
        $this->assertNull($review->fresh()->final_rating);

        $report = app(PerformanceReports::class)->meritLedger('2026-11-15');
        $this->assertNotEmpty($report['rows'] ?? []);
    }

    public function test_a_late_mark_is_evidence_only_when_the_company_says_so_and_once_per_day(): void
    {
        WorkPattern::query()->delete();
        $recorder = app(AttendanceRecorder::class);

        // Zero as shipped: the late mark is recorded and the ledger stays empty.
        $pattern = WorkPattern::create(['name' => 'Office', 'is_default' => true]);
        foreach (range(1, 5) as $weekday) {
            $pattern->days()->create(['weekday' => $weekday, 'is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00']);
        }
        $pattern->days()->createMany([['weekday' => 6, 'is_working' => false], ['weekday' => 7, 'is_working' => false]]);

        $recorder->record($this->employee, '2026-11-09', AttendanceDay::STATUS_PRESENT, '09:45', '18:00');
        $this->assertSame(0, MeritEntry::count());

        app(TenantSettings::class)->set('performance.merit.late_mark_points', 2);

        $recorder->record($this->employee, '2026-11-10', AttendanceDay::STATUS_PRESENT, '09:40', '18:00');
        $this->assertSame(1, MeritEntry::count());
        $entry = MeritEntry::first();
        $this->assertSame(-2, $entry->points);
        $this->assertSame(MeritEntry::SOURCE_LATE_MARK, $entry->source_type);
        $this->assertSame('2026-11-10', $entry->occurred_on->toDateString());

        // Re-recording the same day, or arriving on time, adds nothing.
        $recorder->record($this->employee, '2026-11-10', AttendanceDay::STATUS_PRESENT, '09:50', '18:30');
        $recorder->record($this->employee, '2026-11-11', AttendanceDay::STATUS_PRESENT, '08:55', '18:00');
        $this->assertSame(1, MeritEntry::count());
    }
}
