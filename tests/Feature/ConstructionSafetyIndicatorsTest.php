<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Pages\SafetyIndicatorsReport;
use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Services\IncidentService;
use App\Modules\ConstructionQhse\Services\SafetyIndicators;
use App\Modules\ConstructionQhse\Support\ExposureHours;
use App\Modules\ConstructionQhse\Support\SafetyRate;
use App\Modules\Core\Models\CompanyModule;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The QHSE indicators — §17.6, Phase 10g, and the sub-phase Phase 10 ends on.
 *
 * Phase 10's stated exit condition is "an NCR that proposes a deduction and never applies one, and **a safety page that
 * refuses to print a rate it cannot compute**". Almost every test below is about the refusal rather than the arithmetic,
 * because the arithmetic is a division and the refusal is the design.
 *
 * Four rules, each with its own section:
 *
 *  - **A missing denominator is not a zero.** §17.6: "with no diary the denominator is zero and the frequency rate
 *    renders as `0.00`, which reads as a perfect safety record and actually means nobody filled anything in." The type
 *    system carries the distinction: `SafetyRate::$value` is null and there is no accessor that yields a number.
 *  - **The base travels with the figure**, because "1,000,000 against 200,000 is a factor of five with both called *the
 *    standard*".
 *  - **One source per job, printed.** Counting the diary *and* Timesheets halves every rate, so the job names one and
 *    the report says which.
 *  - **Nothing is stored**, which is what keeps a reclassified incident from leaving a stale rate behind.
 */
class ConstructionSafetyIndicatorsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private SafetyIndicators $indicators;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'indicators@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create([
            'code' => 'J-1',
            'name' => 'Tower',
            'exposure_hours_source' => 'daily_log',
        ]);
        $this->indicators = app(SafetyIndicators::class);
    }

    /**
     * An approved diary day carrying man-hours.
     *
     * Written straight to the tables rather than through `DailyLogService`, for the same reason `SafetyIndicators` reads
     * them that way: naming the field module's service from a QHSE test would model a coupling the manifest says does
     * not exist. What is under test is the reader, and the reader sees rows.
     */
    private function diaryDay(string $date, float $hours, float $overtime = 0.0, bool $approved = true): int
    {
        $id = DB::table('construction_daily_logs')->insertGetId([
            'job_id' => $this->job->getKey(),
            'log_date' => $date,
            'approved_at' => $approved ? $date.' 18:00:00' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('construction_daily_log_manpower')->insert([
            'daily_log_id' => $id,
            'trade_label' => 'Steel fixers',
            'headcount' => 10,
            'hours' => $hours,
            'overtime_hours' => $overtime,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $attributes */
    private function incident(array $attributes = []): Incident
    {
        return app(IncidentService::class)->report($this->job, array_merge([
            'kind' => Incident::KIND_LOST_TIME,
            'description' => 'Steel fixer fell from the second lift of scaffold.',
            'occurred_at' => '2026-08-10 10:00',
            'reported_at' => '2026-08-10 11:00',
        ], $attributes));
    }

    // ---------------------------------------------------- a missing denominator is not a zero

    /**
     * **The test the whole sub-phase exists for.**
     *
     * With no diary the naive implementation divides by zero — or guards it and returns 0.00, which §17.6 calls out by
     * name: "a perfect safety record" reported by a site where nobody filled anything in.
     */
    public function test_with_no_exposure_hours_the_rate_is_refused_rather_than_zero(): void
    {
        $this->incident();

        $rate = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertFalse($rate->isAvailable(), 'a rate with no denominator is not available');
        $this->assertNull($rate->value, 'and there is no number to print, not even a zero');
        $this->assertNotSame('0.00', $rate->display());
        $this->assertSame('Insufficient exposure data', $rate->display(), '§17.6\'s exact words');
    }

    /**
     * And the refusal is a sentence somebody can act on, not a blank.
     *
     * "Insufficient exposure data" tells a safety manager a rate is missing. It does not tell them that the diaries for
     * August were never approved, which is the thing they can go and fix this morning.
     */
    public function test_the_refusal_says_what_is_missing(): void
    {
        $this->diaryDay('2026-08-05', 400, approved: false);

        $rate = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertFalse($rate->isAvailable());
        $this->assertStringContainsString('No approved site diary', (string) $rate->reason);
        $this->assertStringContainsString('not evidence', (string) $rate->reason);
    }

    /**
     * **An unapproved diary is not a denominator**, which is `DailyLogService::exposureHours()`'s rule restated here.
     *
     * A rate computed from drafts moves every time somebody edits one, and a safety figure that changes without any
     * incident changing is a figure nobody can defend in a meeting.
     */
    public function test_only_approved_diaries_count(): void
    {
        $this->diaryDay('2026-08-05', 400, approved: false);
        $this->assertFalse($this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31')->isAvailable());

        $this->diaryDay('2026-08-06', 400);
        $exposure = $this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31');

        $this->assertTrue($exposure->isAvailable());
        $this->assertSame(400.0, $exposure->hours, 'the draft day is still not in the total');
    }

    /** Overtime is exposure. An hour on site is an hour whatever it was paid at. */
    public function test_overtime_hours_count_towards_exposure(): void
    {
        $this->diaryDay('2026-08-06', 400, overtime: 60);

        $this->assertSame(460.0, $this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31')->hours);
    }

    /**
     * Approved days with no manpower on them are their own refusal, with their own reason.
     *
     * Somebody signed off days that record nobody on site. That is not the same fact as there being no diary, and it is
     * not the same fact as nobody having been there — it is a diary being filled in badly, which is a different thing to
     * go and fix.
     */
    public function test_approved_days_with_no_manpower_are_refused_with_their_own_reason(): void
    {
        DB::table('construction_daily_logs')->insert([
            'job_id' => $this->job->getKey(),
            'log_date' => '2026-08-06',
            'approved_at' => '2026-08-06 18:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exposure = $this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31');

        $this->assertFalse($exposure->isAvailable());
        $this->assertStringContainsString('no man-hours', $exposure->reason);
        $this->assertStringContainsString('not the same as nobody having been on site', $exposure->reason);
    }

    /**
     * **Every rate refuses, not just the headline one.**
     *
     * A page where LTIFR says "insufficient exposure data" and severity rate says 0.00 is worse than one where both
     * refuse, because the reader concludes the second figure is the reliable one.
     */
    public function test_every_lagging_rate_refuses_together(): void
    {
        $this->incident();
        $this->incident(['kind' => Incident::KIND_FIRST_AID, 'days_lost' => null]);

        foreach ([
            $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31'),
            $this->indicators->totalRecordableIncidentRate($this->job, '2026-08-01', '2026-08-31'),
            $this->indicators->accidentFrequencyRate($this->job, '2026-08-01', '2026-08-31'),
            $this->indicators->severityRate($this->job, '2026-08-01', '2026-08-31'),
        ] as $rate) {
            $this->assertFalse($rate->isAvailable(), "{$rate->label} refuses with no denominator");
            $this->assertSame('Insufficient exposure data', $rate->display());
        }
    }

    /**
     * There is no way to get a number out of a refused rate.
     *
     * The point of the DTO rather than a nullable float: a caller cannot write `$rate->value ?? 0` by accident in a
     * Blade template, because `display()` and `describe()` are what a template reaches for and both refuse.
     */
    public function test_a_refused_rate_prints_its_reason_and_never_a_figure(): void
    {
        $rate = SafetyRate::unavailable('LTIFR', 'The diary is empty.', 1_000_000);

        $this->assertStringContainsString('Insufficient exposure data', $rate->describe());
        $this->assertStringContainsString('The diary is empty.', $rate->describe());
        $this->assertStringNotContainsString('0.00', $rate->describe());
    }

    // ---------------------------------------------------------------------- the base

    /**
     * **The base is printed with the figure**, which is §17.6's central complaint about safety statistics.
     */
    public function test_a_rate_carries_the_base_it_was_computed_on(): void
    {
        $this->diaryDay('2026-08-06', 100_000);
        $this->incident();

        $rate = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertTrue($rate->isAvailable());
        $this->assertSame(10.0, $rate->value, 'one injury in 100,000 hours is ten per million');
        $this->assertSame(1_000_000, $rate->base);
        $this->assertStringContainsString('per 1,000,000 hours worked', $rate->describe());
    }

    /**
     * **The factor-of-five sentence, as a test.**
     *
     * §17.6: "1,000,000 against 200,000 is a factor of five with both called *the standard*." Two figures five times
     * apart describing one site, and the only thing that makes either meaningful is the base beside it.
     */
    public function test_the_same_site_is_a_factor_of_five_apart_on_two_bases(): void
    {
        $this->diaryDay('2026-08-06', 100_000);
        $this->incident();

        $perMillion = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31', 1_000_000);
        $perTwoHundredThousand = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31', 200_000);

        $this->assertSame(10.0, $perMillion->value);
        $this->assertSame(2.0, $perTwoHundredThousand->value);
        $this->assertSame($perMillion->value / $perTwoHundredThousand->value, 5.0);

        // Both true, both about the same site, and neither is readable without this.
        $this->assertStringContainsString('per 1,000,000', $perMillion->baseLabel());
        $this->assertStringContainsString('per 200,000', $perTwoHundredThousand->baseLabel());
    }

    /** The default comes from configuration, so a company reports on one basis without choosing it every time. */
    public function test_the_base_defaults_to_configuration(): void
    {
        config()->set('construction.qhse.rate_base', 200_000);
        $this->diaryDay('2026-08-06', 100_000);
        $this->incident();

        $rate = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(200_000, $rate->base);
        $this->assertSame(2.0, $rate->value);
    }

    /**
     * Even a refusal carries the base.
     *
     * "We could not compute this per million hours" is a more useful sentence than "we could not compute this", because
     * the first tells somebody what they were about to be shown.
     */
    public function test_a_refusal_still_carries_the_base(): void
    {
        $rate = $this->indicators->severityRate($this->job, '2026-08-01', '2026-08-31', 200_000);

        $this->assertFalse($rate->isAvailable());
        $this->assertSame(200_000, $rate->base);
    }

    /** The numerator travels too: 5.00 per million on two incidents is a small sample, not a trend. */
    public function test_the_numerator_travels_with_the_rate(): void
    {
        $this->diaryDay('2026-08-06', 400_000);
        $this->incident();
        $this->incident(['occurred_at' => '2026-08-12 09:00', 'reported_at' => '2026-08-12 10:00']);

        $rate = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(5.0, $rate->value);
        $this->assertSame(2, $rate->numerator);
        $this->assertStringContainsString('2 incidents over 400,000 hours', $rate->describe());
    }

    // ------------------------------------------------------- one source per job, printed

    /**
     * **The mirror-image failure**: counting both sources halves every rate.
     *
     * §17.6 asks for one source named on the job, and this is the arithmetic behind the requirement — the same 400,000
     * hours booked in both places would be read as 800,000 and every rate would come out at half.
     */
    public function test_the_job_names_one_source_and_only_that_one_is_read(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'timesheets'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $this->diaryDay('2026-08-06', 400_000);

        $exposure = $this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31');

        // Not 800,000, which is what a reader that summed both sources would find with the same data in each.
        $this->assertSame(400_000.0, $exposure->hours);
        $this->assertStringContainsString('site diary', $exposure->source);
    }

    /** And the report prints which one it used, because the figure alone cannot say. */
    public function test_the_source_is_printed_with_the_figure(): void
    {
        $this->diaryDay('2026-08-06', 400);

        $exposure = $this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31');

        $this->assertStringContainsString('400 exposure hours', $exposure->describe());
        $this->assertStringContainsString('approved manpower returns', $exposure->describe());
    }

    /**
     * **A job that has named nothing is a third state**, and the refusal says so rather than defaulting.
     *
     * A default would have silently chosen for every job in every existing tenant, and the choice it made would have
     * been wrong for whichever half of them keeps the other kind of record.
     */
    public function test_a_job_with_no_source_named_is_told_to_choose_one(): void
    {
        $this->job->update(['exposure_hours_source' => null]);
        $this->diaryDay('2026-08-06', 400);

        $exposure = $this->indicators->exposureHours($this->job->fresh(), '2026-08-01', '2026-08-31');

        $this->assertFalse($exposure->isAvailable(), 'the hours exist and are deliberately not read');
        $this->assertStringContainsString('No exposure source is set', $exposure->reason);
        $this->assertStringContainsString('counting both would halve every rate', $exposure->reason);
    }

    /**
     * A job pointed at a module the company has not licensed is refused with *that* reason.
     *
     * Distinguished from an empty diary on purpose: one is fixed by a licence or a different choice on the job, the
     * other by somebody filling in a form.
     */
    public function test_an_unlicensed_source_module_is_named_in_the_refusal(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_field')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $exposure = $this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31');

        $this->assertFalse($exposure->isAvailable());
        $this->assertStringContainsString('site operations is not licensed', $exposure->reason);
        $this->assertStringContainsString('names another source', $exposure->reason);
    }

    /** Timesheets as the source: minutes read as hours, and the source named accordingly. */
    public function test_timesheets_can_be_the_source(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'timesheets'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $projectId = DB::table('projects')->insertGetId([
            'code' => 'PRJ-TOWER',
            'name' => 'Tower fit-out',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->job->update(['exposure_hours_source' => 'timesheets', 'project_id' => $projectId]);

        $employeeId = \App\Modules\Employees\Models\Employee::create([
            'user_id' => $this->makeUser('Employee', 'fixer@test.local')->id,
            'employee_id' => 'EMP-FIX',
            'gender' => 'Male',
        ])->getKey();

        // Two entries, one non-billable. Both are exposure: remedial work is exactly the work people get hurt on, and a
        // filter on `is_billable` would have dropped it.
        foreach ([[480, true], [120, false]] as [$minutes, $billable]) {
            DB::table('timesheet_entries')->insert([
                'employee_id' => $employeeId,
                'project_id' => $projectId,
                'date' => '2026-08-06',
                'minutes' => $minutes,
                'is_billable' => $billable,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exposure = $this->indicators->exposureHours($this->job->fresh(), '2026-08-01', '2026-08-31');

        $this->assertSame(10.0, $exposure->hours, '600 minutes is ten hours, billable or not');
        $this->assertStringContainsString('timesheet hours', $exposure->source);
    }

    // ------------------------------------------------------------------ what the rates count

    /**
     * **First aid is out of the recordable rate and in the accident rate.**
     *
     * §17.6's complaint about incomparable figures applies to numerators as well as denominators: a recordable rate that
     * quietly includes first aid is five times somebody else's and looks like a worse site.
     */
    public function test_first_aid_is_outside_the_recordable_rate_and_inside_the_accident_rate(): void
    {
        $this->diaryDay('2026-08-06', 1_000_000);
        $this->incident(['kind' => Incident::KIND_FIRST_AID]);
        $this->incident([
            'kind' => Incident::KIND_MEDICAL_TREATMENT,
            'occurred_at' => '2026-08-11 09:00',
            'reported_at' => '2026-08-11 10:00',
        ]);

        $recordable = $this->indicators->totalRecordableIncidentRate($this->job, '2026-08-01', '2026-08-31');
        $accident = $this->indicators->accidentFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(1.0, $recordable->value, 'medical treatment only');
        $this->assertSame(2.0, $accident->value, 'and first aid as well');
    }

    /** A near miss hurt nobody, so it is in no injury rate at all — but it is the leading indicator that matters. */
    public function test_a_near_miss_is_in_no_injury_rate(): void
    {
        $this->diaryDay('2026-08-06', 1_000_000);
        $this->incident(['kind' => Incident::KIND_NEAR_MISS]);

        $this->assertSame(0.0, $this->indicators->accidentFrequencyRate($this->job, '2026-08-01', '2026-08-31')->value);
        $this->assertSame(1, $this->indicators->leadingIndicators($this->job, '2026-08-01', '2026-08-31')['near_miss_reports']);
    }

    /**
     * **Severity and frequency are a pair and neither replaces the other.**
     *
     * Ten one-day cases and one ten-day case give the same severity rate and frequency rates ten times apart. A page
     * showing only one of them cannot tell a site with many small injuries from a site with one serious one.
     */
    public function test_severity_counts_days_and_frequency_counts_cases(): void
    {
        $this->diaryDay('2026-08-06', 1_000_000);
        $this->incident(['days_lost' => 10]);

        $severity = $this->indicators->severityRate($this->job, '2026-08-01', '2026-08-31');
        $frequency = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(10.0, $severity->value);
        $this->assertSame(1.0, $frequency->value);
        $this->assertStringContainsString('10 days lost', $severity->describe());
    }

    /**
     * A rate counts what *happened* in the window, not what was typed in it.
     *
     * `Incident::occurredBetween()` is by `occurred_at`, and August's rate has to contain August's incidents whatever
     * month somebody wrote them up.
     */
    public function test_the_window_is_when_it_happened_not_when_it_was_written_up(): void
    {
        $this->diaryDay('2026-08-06', 1_000_000);
        $this->incident(['occurred_at' => '2026-07-28 09:00', 'reported_at' => '2026-08-03 09:00']);

        $august = $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(0.0, $august->value, 'a July incident reported in August belongs to July');
    }

    // ------------------------------------------------------- the leading indicators

    /**
     * **Every leading indicator that can be unknown is null, not zero.**
     *
     * §17.6's argument applied to the other half of the page: 0% induction coverage on an empty register would tell
     * somebody to go and induct people who are not there, and 0% permits closed on time would report a failure by a site
     * that has raised no permits.
     */
    public function test_leading_indicators_are_null_rather_than_a_flattering_zero(): void
    {
        $leading = $this->indicators->leadingIndicators($this->job, '2026-08-01', '2026-08-31');

        $this->assertNull($leading['induction_coverage_percent'], 'no register is not nought per cent inducted');
        $this->assertNull($leading['permits_closed_on_time_percent'], 'no permits is not nought per cent on time');
        $this->assertNull($leading['hold_points_first_time_percent']);
        $this->assertNull($leading['toolbox_average_attendance']);
        $this->assertNull($leading['inspections_planned'], 'no plan in force is not nought points planned');
    }

    /**
     * They survive the absence that silences the rates.
     *
     * None of them needs exposure hours, so a site with no diary still has a page worth reading — which is the argument
     * for both halves being on one page.
     */
    public function test_the_leading_indicators_do_not_need_a_denominator(): void
    {
        $this->incident(['kind' => Incident::KIND_NEAR_MISS]);
        $this->incident([
            'kind' => 'unsafe_condition',
            'occurred_at' => '2026-08-11 08:00',
            'reported_at' => '2026-08-11 08:30',
        ]);

        $this->assertFalse($this->indicators->exposureHours($this->job, '2026-08-01', '2026-08-31')->isAvailable());
        $this->assertSame(2, $this->indicators->leadingIndicators($this->job, '2026-08-01', '2026-08-31')['near_miss_reports']);
    }

    /**
     * Planned inspections means the hold and witness points on the plans **in force**.
     *
     * A draft ITP is a proposal. Counting its points as planned would report a site as behind on inspections it has not
     * yet committed to doing.
     */
    public function test_planned_points_come_from_plans_in_force(): void
    {
        $itp = Itp::create([
            'job_id' => $this->job->getKey(),
            'reference' => 'ITP-1',
            'title' => 'Concrete',
            'status' => Itp::STATUS_DRAFT,
        ]);
        ItpActivity::create([
            'itp_id' => $itp->getKey(),
            'sequence' => 1,
            'activity_description' => 'Pre-pour check',
            'point_type' => ItpActivity::POINT_HOLD,
        ]);

        $this->assertNull(
            $this->indicators->leadingIndicators($this->job, '2026-08-01', '2026-08-31')['inspections_planned'],
            'a draft plan commits to nothing',
        );

        $itp->update(['status' => Itp::STATUS_APPROVED]);

        $this->assertSame(
            1,
            $this->indicators->leadingIndicators($this->job, '2026-08-01', '2026-08-31')['inspections_planned'],
        );
    }

    /** A review point is not an attendance, so it is not in the planned figure. */
    public function test_review_points_are_not_counted_as_planned_attendances(): void
    {
        $itp = Itp::create([
            'job_id' => $this->job->getKey(),
            'reference' => 'ITP-2',
            'title' => 'Cladding',
            'status' => Itp::STATUS_APPROVED,
        ]);
        ItpActivity::create([
            'itp_id' => $itp->getKey(),
            'sequence' => 1,
            'activity_description' => 'Check the shop drawings',
            'point_type' => ItpActivity::POINT_REVIEW,
        ]);

        $this->assertNull(
            $this->indicators->leadingIndicators($this->job, '2026-08-01', '2026-08-31')['inspections_planned'],
        );
    }

    /**
     * **No lost-time injury is the good state and must not read as a bad ratio.**
     *
     * The near-miss ratio's denominator being zero is the outcome everybody wants, and a division that returned zero
     * there would report the safest site on the books as the one with no reporting culture.
     */
    public function test_the_near_miss_ratio_refuses_when_there_is_nothing_to_divide_by(): void
    {
        $this->incident(['kind' => Incident::KIND_NEAR_MISS]);

        $ratio = $this->indicators->nearMissRatio($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(1, $ratio['near_misses']);
        $this->assertSame(0, $ratio['lost_time']);
        $this->assertNull($ratio['ratio']);
    }

    // ------------------------------------------------------------------------- the page

    /** The report renders, and the refusal is what a site with no diary sees. */
    public function test_the_page_prints_the_refusal_rather_than_a_rate(): void
    {
        $this->incident();

        Livewire::test(SafetyIndicatorsReport::class)
            ->assertSuccessful()
            ->assertSee('Insufficient exposure data')
            ->assertDontSee('0.00');
    }

    /** And with hours it prints the figure, the base, and which source it came from. */
    public function test_the_page_prints_the_figure_the_base_and_the_source(): void
    {
        $this->diaryDay('2026-08-06', 100_000);
        $this->incident();

        Livewire::test(SafetyIndicatorsReport::class)
            ->assertSuccessful()
            ->assertSee('10.00')
            ->assertSee('per 1,000,000 hours worked')
            ->assertSee('approved manpower returns')
            ->assertDontSee('Insufficient exposure data');
    }

    /**
     * **Nothing on the page is stored**, which is what keeps a reclassified incident from leaving a stale rate behind.
     *
     * A first-aid case becomes a lost-time case the day somebody does not come back, and §17.6 asks for these to be
     * "computed on a report page and never stored" for exactly that reason. Asserted by reclassifying and reading again:
     * a stored figure would still be reporting the old kind.
     */
    public function test_reclassifying_an_incident_changes_the_rate_immediately(): void
    {
        $this->diaryDay('2026-08-06', 1_000_000);
        $incident = $this->incident(['kind' => Incident::KIND_FIRST_AID]);

        $this->assertSame(0.0, $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31')->value);

        $incident->update(['kind' => Incident::KIND_LOST_TIME, 'days_lost' => 4]);

        $this->assertSame(1.0, $this->indicators->lostTimeInjuryFrequencyRate($this->job, '2026-08-01', '2026-08-31')->value);
        $this->assertSame(4.0, $this->indicators->severityRate($this->job, '2026-08-01', '2026-08-31')->value);
    }

    /**
     * There is no table holding any of this.
     *
     * The plan's "never stored" is a claim about the schema, not a habit of the service, so it is asserted against the
     * schema — a later phase that added a snapshot table for reporting speed would fail here and have to argue for it.
     */
    public function test_no_indicator_is_persisted_anywhere(): void
    {
        foreach (['construction_safety_indicators', 'construction_qhse_indicators', 'construction_safety_rates'] as $table) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasTable($table),
                "{$table} does not exist: §17.6's indicators are computed and never stored",
            );
        }
    }

    /**
     * The page selects the base, and the base changes every figure on it visibly.
     *
     * §17.6's defence is not one base for everybody — a Gulf client's pack asks for a million and a UK insurer asks for a
     * hundred thousand — it is the base being on the face of the figure whichever is chosen.
     */
    public function test_the_page_can_change_the_base_and_says_which_it_used(): void
    {
        $this->diaryDay('2026-08-06', 100_000);
        $this->incident();

        Livewire::test(SafetyIndicatorsReport::class)
            ->set('data.base', 200_000)
            ->assertSee('2.00')
            ->assertSee('per 200,000 hours worked');
    }

    /** The rate base is on the section heading too, because a heading is what ends up in a screenshot. */
    public function test_an_exposure_dto_describes_itself_either_way(): void
    {
        $this->assertStringContainsString(
            '400 exposure hours, from the diary',
            ExposureHours::of(400, 'the diary')->describe(),
        );
        $this->assertStringContainsString(
            'Insufficient exposure data. Nobody filled anything in.',
            ExposureHours::unavailable('Nobody filled anything in.')->describe(),
        );
    }
}
