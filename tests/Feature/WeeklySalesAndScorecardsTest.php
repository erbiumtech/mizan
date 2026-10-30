<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Crm\Models\WeeklySalesReport;
use App\Modules\Crm\Support\WeeklySalesReports;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\ScorecardProfile;
use App\Modules\Performance\Support\Scorecards;
use Illuminate\Support\Carbon;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The weekly sales report and scorecards — docs/saas-launch-plan.md §14.10.
 *
 * Three judgements worth pinning: that the source of the weekly figures is a
 * licensing fact rather than a setting, that a quiet week is still a row, and that
 * quality and volume are never folded into the net.
 */
class WeeklySalesAndScorecardsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    private Employee $rep;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AS_OF.' 10:00:00');

        $this->actingAs($this->makeUser('Administrator', 'sales-hr@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'performance'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        // CRM off by default here: the form is what a company without the pipeline uses,
        // and that is the branch worth testing first.
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'crm'],
            ['licensed' => false, 'enabled' => false],
        );

        modules()->flush();

        $this->rep = Employee::create([
            'employee_id' => 'EMP-S1', 'name' => 'A Rep', 'gender' => 'Male', 'is_active' => true,
            'designation' => 'Sales Executive', 'date_of_joining' => '2021-01-01',
        ]);
    }

    private function rows(array $report): array
    {
        return $report['rows'] ?? [];
    }

    // ---------------------------------------------------------- weekly sales

    public function test_a_week_is_the_monday_whatever_day_somebody_picks(): void
    {
        $this->assertSame('2026-08-17', WeeklySalesReport::weekStarting('2026-08-20'));
        $this->assertSame('2026-08-17', WeeklySalesReport::weekStarting('2026-08-17'));
        $this->assertSame('2026-08-17', WeeklySalesReport::weekStarting('2026-08-23'), 'Sunday still belongs to its Monday');
    }

    public function test_without_the_pipeline_the_report_reads_the_form(): void
    {
        WeeklySalesReport::create([
            'employee_id' => $this->rep->getKey(),
            'week_starting' => WeeklySalesReport::weekStarting(self::AS_OF),
            'visits' => 7, 'calls' => 21, 'quotes' => 3, 'orders' => 2, 'order_value' => 180000,
        ]);

        $rows = $this->rows(app(WeeklySalesReports::class)->week(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame('Sent', $rows[0][1]);
        $this->assertSame('7', $rows[0][2]);
        $this->assertSame('2', $rows[0][5]);
        $this->assertSame('180,000', $rows[0][6]);
    }

    public function test_somebody_who_sent_nothing_is_still_a_row(): void
    {
        $rows = $this->rows(app(WeeklySalesReports::class)->week(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame(
            'Not sent',
            $rows[0][1],
            'a report that dropped the quiet weeks would be a report of the busy ones',
        );
    }

    public function test_a_form_for_another_week_is_not_this_week(): void
    {
        WeeklySalesReport::create([
            'employee_id' => $this->rep->getKey(),
            'week_starting' => '2026-08-10',
            'visits' => 9,
        ]);

        $this->assertSame('Not sent', $this->rows(app(WeeklySalesReports::class)->week(self::AS_OF))[0][1]);
    }

    public function test_with_the_pipeline_the_report_says_so_and_stops_reading_forms(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'crm'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        // A form exists and is deliberately ignored: a figure derived from records that
        // exist is not improved by one somebody remembered.
        WeeklySalesReport::create([
            'employee_id' => $this->rep->getKey(),
            'week_starting' => WeeklySalesReport::weekStarting(self::AS_OF),
            'visits' => 99,
        ]);

        $rows = $this->rows(app(WeeklySalesReports::class)->week(self::AS_OF));

        $this->assertSame('Nothing recorded', $rows[0][1]);
        $this->assertStringNotContainsString('99', implode(' ', $rows[0]));
    }

    // -------------------------------------------------------------- scorecards

    private function merit(string $source, int $points, string $on = '2026-08-05'): MeritEntry
    {
        return MeritEntry::create([
            'employee_id' => $this->rep->getKey(),
            'occurred_on' => $on,
            'points' => $points,
            'source_type' => $source,
            'reason' => 'Test',
        ]);
    }

    public function test_quality_and_volume_are_reported_apart_from_the_net(): void
    {
        $this->merit(MeritEntry::SOURCE_REPORT, 6);
        $this->merit(MeritEntry::SOURCE_TASK, -4);

        $rows = $this->rows(app(Scorecards::class)->scorecard(self::AS_OF));

        $this->assertCount(1, $rows);
        $this->assertSame('+6', $rows[0][3], 'quality');
        $this->assertSame('-4', $rows[0][4], 'volume');
        $this->assertSame('+2', $rows[0][5], 'and the net, which hides the other two on its own');
    }

    public function test_a_designation_with_no_profile_counts_everything_once(): void
    {
        $this->merit(MeritEntry::SOURCE_TASK, -4);

        $rows = $this->rows(app(Scorecards::class)->scorecard(self::AS_OF));

        $this->assertSame('Unweighted', $rows[0][2]);
        $this->assertSame('-4', $rows[0][4]);
    }

    public function test_a_profile_weights_the_ledger_for_that_job(): void
    {
        ScorecardProfile::create([
            'designation' => 'Sales Executive',
            // A job where lateness matters more is a job where a late mark costs more.
            'weights' => [MeritEntry::SOURCE_TASK => 2, MeritEntry::SOURCE_REPORT => 0.5],
        ]);

        $this->merit(MeritEntry::SOURCE_REPORT, 6);
        $this->merit(MeritEntry::SOURCE_TASK, -4);

        $rows = $this->rows(app(Scorecards::class)->scorecard(self::AS_OF));

        $this->assertSame('Weighted', $rows[0][2]);
        $this->assertSame('+3', $rows[0][3], 'six at half weight');
        $this->assertSame('-8', $rows[0][4], 'minus four at double');
        $this->assertSame('-5', $rows[0][5]);
    }

    public function test_a_profile_switched_off_stops_applying_and_is_not_lost(): void
    {
        $profile = ScorecardProfile::create([
            'designation' => 'Sales Executive',
            'weights' => [MeritEntry::SOURCE_TASK => 2],
            'is_active' => false,
        ]);

        $this->merit(MeritEntry::SOURCE_TASK, -4);

        $this->assertSame('Unweighted', $this->rows(app(Scorecards::class)->scorecard(self::AS_OF))[0][2]);
        $this->assertSame(2.0, $profile->weightFor(MeritEntry::SOURCE_TASK), 'and what was written is still there');
    }

    public function test_somebody_with_nothing_on_the_ledger_is_left_off(): void
    {
        $this->assertSame([], $this->rows(app(Scorecards::class)->scorecard(self::AS_OF)));
    }
}
