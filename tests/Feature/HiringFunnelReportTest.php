<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Filament\Pages\HiringFunnel;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The hiring funnel — `docs/reports-expansion-plan.md` Phase 3.2.
 *
 * Nothing here reconciles to anything: Phase 3 is operational reporting and there is no account behind a
 * funnel. So what these tests hold the report to is the discipline that replaces reconciliation — **not
 * overstating what it knows.** Four decisions carry that, and each is a way the report could read as more
 * confident than the data supports:
 *
 *  - an acceptance rate over offers **answered**, not issued, because an unanswered offer is not a refusal;
 *  - time-to-offer measured to `issued_at`, because a draft nobody sent is not a milestone;
 *  - time-to-join over **accepted** offers only, because a declined offer's joining date never happened;
 *  - ageing only for vacancies still open, because a closed vacancy's age is a historical fact and averaging
 *    the two together is meaningless.
 *
 * Plus the one modelling decision a reader would otherwise misread: a withdrawal is not a rejection.
 */
class HiringFunnelReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2026-09-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        $this->setModule('recruitment', true);
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

    private function vacancy(string $code, array $attributes = []): Vacancy
    {
        return Vacancy::create(array_merge([
            'code' => $code,
            'title' => 'Role '.$code,
            'status' => Vacancy::STATUS_OPEN,
            'opened_on' => '2026-08-01',
        ], $attributes));
    }

    private function apply(Vacancy $vacancy, string $stage, string $appliedOn = '2026-08-05'): Application
    {
        $applicant = Applicant::create([
            'name' => 'Candidate '.Applicant::query()->count(),
            'email' => 'candidate'.Applicant::query()->count().'@example.test',
        ]);

        return Application::create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'applied_on' => $appliedOn,
            'stage' => $stage,
        ]);
    }

    private function offer(Application $application, array $attributes = []): Offer
    {
        return Offer::create(array_merge([
            'application_id' => $application->id,
            'salary' => 150_000,
            'joining_date' => '2026-09-15',
            'status' => Offer::STATUS_ISSUED,
            'issued_at' => '2026-08-20 10:00:00',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('HiringFunnel', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for HiringFunnel');

        return $payload;
    }

    private function cell(array $payload, string $code, string $column): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "no [{$column}] column in ".implode(' | ', $payload['columns']));

        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $code)) {
                return $row[$index];
            }
        }

        $this->fail("no row for [{$code}]");
    }

    // ─────────────────────────────────────────────────────────── the funnel ──

    /** A column per stage, and a dash where nobody has reached one. */
    public function test_each_stage_is_counted_and_an_empty_stage_reads_as_a_dash(): void
    {
        $vacancy = $this->vacancy('V-1');
        $this->apply($vacancy, Application::STAGE_APPLIED);
        $this->apply($vacancy, Application::STAGE_APPLIED);
        $this->apply($vacancy, Application::STAGE_INTERVIEW);
        $this->apply($vacancy, Application::STAGE_REJECTED);

        $payload = $this->report();

        $this->assertSame('2', $this->cell($payload, 'V-1', 'Applied'));
        $this->assertSame('1', $this->cell($payload, 'V-1', 'Interview'));
        $this->assertSame('1', $this->cell($payload, 'V-1', 'Rejected'));
        $this->assertSame('—', $this->cell($payload, 'V-1', 'Screening'));
        $this->assertSame(4.0, $payload['tiles'][0]['value']);
    }

    /**
     * A withdrawal is not a rejection, and is not in any stage column.
     *
     * An applicant who withdrew left of their own accord: counting them beside rejections would read as the
     * company's decision. They are still in the applications total, because they did apply, and the note
     * says how many — which is what stops the stage columns looking as though they have lost somebody.
     */
    public function test_a_withdrawal_is_counted_apart_from_a_rejection(): void
    {
        $vacancy = $this->vacancy('V-1');
        $this->apply($vacancy, Application::STAGE_REJECTED);
        $this->apply($vacancy, Application::STAGE_WITHDRAWN);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'V-1', 'Rejected'));
        $this->assertSame(2.0, $payload['tiles'][0]['value'], 'both applied');
        $this->assertStringContainsString('1 APPLICANT WITHDREW, NOT COUNTED AS REJECTED', $payload['note']);
    }

    /** Two vacancies are two rows, each with its own funnel. */
    public function test_each_vacancy_gets_its_own_row(): void
    {
        $this->apply($this->vacancy('V-1'), Application::STAGE_APPLIED);
        $this->apply($this->vacancy('V-2'), Application::STAGE_HIRED);

        $payload = $this->report();

        $this->assertCount(2, $payload['rows']);
        $this->assertSame('1', $this->cell($payload, 'V-1', 'Applied'));
        $this->assertSame('1', $this->cell($payload, 'V-2', 'Hired'));
    }

    // ────────────────────────────────────────────── the acceptance rate ──

    /**
     * Accepted over *answered*, not over issued.
     *
     * One accepted, one declined, one still out. The honest rate is 50% — a company that has just sent an
     * offer has not lost it, and counting the pending one as a refusal would report 33%.
     */
    public function test_the_acceptance_rate_ignores_offers_nobody_has_answered(): void
    {
        $vacancy = $this->vacancy('V-1');
        $this->offer($this->apply($vacancy, Application::STAGE_HIRED), ['status' => Offer::STATUS_ACCEPTED, 'responded_at' => '2026-08-25 09:00:00']);
        $this->offer($this->apply($vacancy, Application::STAGE_REJECTED), ['status' => Offer::STATUS_DECLINED, 'responded_at' => '2026-08-26 09:00:00']);
        $this->offer($this->apply($vacancy, Application::STAGE_OFFER));

        $this->assertSame('50%', $this->cell($this->report(), 'V-1', 'Offers taken'));
    }

    /** And a vacancy where nothing has been answered has no rate at all. */
    public function test_a_vacancy_with_no_answered_offer_has_no_rate(): void
    {
        $vacancy = $this->vacancy('V-1');
        $this->offer($this->apply($vacancy, Application::STAGE_OFFER));

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'V-1', 'Offers taken'));
        $this->assertStringContainsString('NO OFFER HAS BEEN ANSWERED YET', $payload['note']);
    }

    // ─────────────────────────────────────────────────── time to hire ──

    /** Applied to offer issued, in days. */
    public function test_time_to_offer_is_measured_to_the_offer_going_out(): void
    {
        $vacancy = $this->vacancy('V-1');
        // Applied on the 5th, offered on the 20th.
        $this->offer($this->apply($vacancy, Application::STAGE_OFFER, '2026-08-05'));

        $this->assertSame('15', $this->cell($this->report(), 'V-1', 'To offer'));
    }

    /**
     * A draft offer nobody has sent is not a milestone.
     *
     * `issued_at` null means the offer exists in the system and not in the candidate's inbox, so it cannot
     * contribute to how long hiring took.
     */
    public function test_an_unissued_offer_does_not_count_towards_time_to_offer(): void
    {
        $vacancy = $this->vacancy('V-1');
        $this->offer($this->apply($vacancy, Application::STAGE_OFFER), ['status' => Offer::STATUS_DRAFT, 'issued_at' => null]);

        $this->assertSame('—', $this->cell($this->report(), 'V-1', 'To offer'));
    }

    /**
     * Time to join counts accepted offers only.
     *
     * A declined offer has a joining date nobody is going to honour. Averaging it in would describe a notice
     * period that never happened — and here it would halve the figure.
     */
    public function test_time_to_join_counts_only_accepted_offers(): void
    {
        $vacancy = $this->vacancy('V-1');
        // Issued 20 Aug, joining 15 Sep: 26 days.
        $this->offer($this->apply($vacancy, Application::STAGE_HIRED), [
            'status' => Offer::STATUS_ACCEPTED,
            'responded_at' => '2026-08-25 09:00:00',
        ]);
        // Declined, with a much nearer joining date that must not pull the average down.
        $this->offer($this->apply($vacancy, Application::STAGE_REJECTED), [
            'status' => Offer::STATUS_DECLINED,
            'responded_at' => '2026-08-26 09:00:00',
            'joining_date' => '2026-08-21',
        ]);

        $this->assertSame('26', $this->cell($this->report(), 'V-1', 'To join'));
    }

    // ──────────────────────────────────────────────────────── ageing ──

    /** An open vacancy is aged from the day it opened. */
    public function test_an_open_vacancy_is_aged(): void
    {
        $this->apply($this->vacancy('V-1', ['opened_on' => '2026-08-01']), Application::STAGE_APPLIED);

        // 1 August to 20 September.
        $this->assertSame('50d', $this->cell($this->report(), 'V-1', 'Open for'));
    }

    /**
     * A filled vacancy is not aged, because ageing is a question about something still open.
     *
     * Putting a closed vacancy's age in the same column would invite the two to be averaged together, and
     * "our vacancies are open 90 days on average" computed over closed ones is a different and misleading
     * statement.
     */
    public function test_a_filled_vacancy_is_not_aged(): void
    {
        $this->apply($this->vacancy('V-1', ['status' => Vacancy::STATUS_FILLED]), Application::STAGE_HIRED);

        $this->assertSame('—', $this->cell($this->report(), 'V-1', 'Open for'));
    }

    /** A vacancy on hold is still open work and is still aged. */
    public function test_a_vacancy_on_hold_is_still_aged(): void
    {
        $this->apply($this->vacancy('V-1', ['status' => Vacancy::STATUS_ON_HOLD]), Application::STAGE_APPLIED);

        $this->assertSame('50d', $this->cell($this->report(), 'V-1', 'Open for'));
    }

    /** A vacancy opened after the date is not on the report at all. */
    public function test_a_vacancy_opened_later_is_not_listed(): void
    {
        $this->apply($this->vacancy('V-1', ['opened_on' => '2026-10-01']), Application::STAGE_APPLIED);

        $this->assertSame([], $this->report()['rows']);
    }

    // ───────────────────────────────────────────────────── the shape ──

    /** Wide, because six stage columns plus four measures is past the width of the pane. */
    public function test_the_funnel_declares_itself_wide(): void
    {
        $this->apply($this->vacancy('V-1'), Application::STAGE_APPLIED);

        $payload = $this->report();

        $this->assertTrue($payload['wide']);
        // Vacancy, six stages, and the four measures.
        $this->assertCount(11, $payload['columns']);
    }

    /** No vacancy is a sentence, not an empty grid. */
    public function test_it_says_when_no_vacancy_has_been_opened(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO VACANCY HAS BEEN OPENED', $payload['note']);
    }

    /** A vacancy nobody has applied to says so rather than showing a rate. */
    public function test_a_vacancy_with_no_applicants_says_so(): void
    {
        $this->vacancy('V-1');

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('NOBODY HAS APPLIED YET', $payload['note']);
    }

    /** Two queries for the applications and offers, whatever the number of vacancies. */
    public function test_it_does_not_query_per_vacancy(): void
    {
        foreach (range(1, 10) as $i) {
            $vacancy = $this->vacancy('V-'.$i);
            $this->offer($this->apply($vacancy, Application::STAGE_OFFER));
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(10, $payload['rows']);
        $this->assertLessThanOrEqual(
            6,
            $queries,
            "the report ran {$queries} queries for ten vacancies, which is per-vacancy rather than aggregate",
        );
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->apply($this->vacancy('V-1'), Application::STAGE_APPLIED);

        $onThePage = Livewire::test(HiringFunnel::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('HiringFunnel', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(HiringFunnel::canAccess());
    }
}
