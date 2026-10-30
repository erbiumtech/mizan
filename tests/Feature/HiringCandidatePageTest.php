<?php

namespace Tests\Feature;

use App\Modules\Recruitment\Filament\Resources\Applications\Pages\ViewApplication;
use App\Modules\Recruitment\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Services\Interviews;
use App\Modules\Recruitment\Support\Pipeline;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The candidate page, and the rounds it exists to show.
 *
 * An application could always carry as many rounds as a company wanted — `Interviews::schedule()`
 * numbers them — and until this there was nowhere to read them as a sequence. What is asserted here
 * is that second and third rounds are ordinary: nothing below is a special case for round one.
 */
class HiringCandidatePageTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'hiring@test.local'));
        $this->setCurrentTenant();
    }

    private function application(string $stage = Application::STAGE_INTERVIEW, string $name = 'Hamza Iqbal'): Application
    {
        $vacancy = Vacancy::create(['code' => 'V-'.uniqid(), 'title' => 'Senior Laravel Developer', 'status' => Vacancy::STATUS_OPEN, 'openings' => 1]);
        $applicant = Applicant::create(['name' => $name, 'email' => str($name)->slug().'@test.local', 'source' => 'Referral']);

        return Application::create([
            'vacancy_id' => $vacancy->id,
            'applicant_id' => $applicant->id,
            'stage' => $stage,
            'applied_on' => '2026-10-29',
        ]);
    }

    public function test_the_page_names_the_candidate_and_what_they_applied_for(): void
    {
        $application = $this->application();

        Livewire::test(ViewApplication::class, ['record' => $application->getKey()])
            ->assertSuccessful()
            ->assertSee('Hamza Iqbal')
            ->assertSee('Applied for Senior Laravel Developer on 29 Oct 2026');
    }

    /**
     * Three rounds, in the order they happened, each with its own panel and outcome.
     *
     * `assertSeeInOrder` rather than three `assertSee`s: a page that lists them in whatever order the
     * database returned would pass three separate assertions and still be wrong, and the order is the
     * only reason to put them on one page.
     */
    public function test_every_round_is_listed_oldest_first(): void
    {
        $application = $this->application();
        $one = $this->makeUser('Manager', 'panel-a@test.local');
        $two = $this->makeUser('Manager', 'panel-b@test.local');

        $first = app(Interviews::class)->schedule($application, '2026-11-03 11:00', [$one->getKey()], 'phone');
        app(Interviews::class)->decide($first, Interview::OUTCOME_PASSED);

        $second = app(Interviews::class)->schedule($application, '2026-11-07 15:00', [$one->getKey(), $two->getKey()], 'in_person', 'Head office');
        $third = app(Interviews::class)->schedule($application, '2026-11-12 09:30', [$two->getKey()], 'video');

        $this->assertSame([1, 2, 3], [$first->round, $second->round, $third->round]);

        Livewire::test(ViewApplication::class, ['record' => $application->getKey()])
            ->assertSuccessful()
            ->assertSeeInOrder(['Round 1', 'Round 2', 'Round 3'])
            ->assertSee('Passed')
            ->assertSee('Head office')
            // The panel by name, which is the question "who saw them" that the list cannot answer.
            ->assertSee($one->name)
            ->assertSee($two->name);
    }

    public function test_a_candidate_with_no_interviews_is_told_so_rather_than_shown_an_empty_box(): void
    {
        Livewire::test(ViewApplication::class, ['record' => $this->application(Application::STAGE_SCREENING)->getKey()])
            ->assertSuccessful()
            ->assertSee('No interviews have been scheduled yet');
    }

    /** Where they are, what they have passed, and what is still ahead. */
    public function test_the_pipeline_marks_what_is_done_where_they_are_and_what_is_left(): void
    {
        $steps = Pipeline::for($this->application(Application::STAGE_INTERVIEW));

        $this->assertSame(
            [Pipeline::DONE, Pipeline::DONE, Pipeline::CURRENT, Pipeline::TODO, Pipeline::TODO],
            array_column($steps, 'state'),
        );
        $this->assertSame(['Applied', 'Screening', 'Interview', 'Offer', 'Hired'], array_column($steps, 'label'));
    }

    /** Hired is an end, not a place somebody is waiting. */
    public function test_a_hired_candidate_has_no_current_step(): void
    {
        $steps = Pipeline::for($this->application(Application::STAGE_HIRED, 'Ayesha Noor'));

        $this->assertSame(array_fill(0, 5, Pipeline::DONE), array_column($steps, 'state'));
    }

    /**
     * A rejection loses where it happened; the record still knows.
     *
     * The stage column reads `rejected` and nothing else, so a line drawn from it alone would say
     * every rejected candidate was turned away on application — including one who was rejected after
     * an offer. The evidence on the record answers instead.
     */
    public function test_a_rejected_candidate_keeps_the_ground_they_covered(): void
    {
        $application = $this->application(Application::STAGE_INTERVIEW, 'Bilal Khan');
        app(Interviews::class)->schedule($application, '2026-11-03 11:00', [$this->makeUser('Manager', 'panel-c@test.local')->getKey()]);

        Offer::create([
            'application_id' => $application->getKey(),
            'salary' => 400000,
            'joining_date' => '2026-12-01',
            'status' => Offer::STATUS_DECLINED,
            'issued_at' => now(),
        ]);

        $application->update(['stage' => Application::STAGE_REJECTED, 'rejected_reason' => 'Took another role', 'rejected_at' => now()]);

        $this->assertTrue(Pipeline::hasEnded($application->fresh()));
        $this->assertSame(Application::STAGE_OFFER, Pipeline::reachedBy($application->fresh()));
        $this->assertSame(
            [Pipeline::DONE, Pipeline::DONE, Pipeline::DONE, Pipeline::DONE, Pipeline::TODO],
            array_column(Pipeline::for($application->fresh()), 'state'),
            'they got as far as an offer, and the line says so',
        );

        Livewire::test(ViewApplication::class, ['record' => $application->getKey()])
            ->assertSuccessful()
            ->assertSee('Took another role');
    }

    /** The summary each round carries: who has replied, and what they said on average. */
    public function test_a_round_says_how_much_of_its_feedback_is_in(): void
    {
        $application = $this->application(Application::STAGE_INTERVIEW, 'Sana Riaz');
        $one = $this->makeUser('Manager', 'panel-d@test.local');
        $two = $this->makeUser('Manager', 'panel-e@test.local');

        $interview = app(Interviews::class)->schedule($application, '2026-11-03 11:00', [$one->getKey(), $two->getKey()]);
        app(Interviews::class)->recordFeedback($interview, $one, ['rating' => 4, 'recommendation' => 'hire']);

        $rounds = app(Interviews::class)->rounds($application);

        $this->assertCount(1, $rounds);
        $this->assertSame(['given' => 1, 'of' => 2, 'average' => 4.0], $rounds[0]['feedback']);
        $this->assertSame('Scheduled', $rounds[0]['outcomeLabel'], 'pending reads as scheduled to anybody but a developer');
        $this->assertSame([$one->name, $two->name], $rounds[0]['panel']);

        Livewire::test(ViewApplication::class, ['record' => $application->getKey()])
            ->assertSuccessful()
            ->assertSee('1 of 2 replied');
    }

    /** The list gathers a candidate's rounds instead of scattering them by date. */
    public function test_the_interviews_list_groups_by_candidate(): void
    {
        $mine = $this->application(Application::STAGE_INTERVIEW, 'Hamza Iqbal');
        $theirs = $this->application(Application::STAGE_INTERVIEW, 'Ayesha Noor');
        $panel = $this->makeUser('Manager', 'panel-f@test.local');

        // Interleaved in time on purpose: sorted by date these four alternate between the two people.
        app(Interviews::class)->schedule($mine, '2026-11-01 10:00', [$panel->getKey()]);
        app(Interviews::class)->schedule($theirs, '2026-11-02 10:00', [$panel->getKey()]);
        app(Interviews::class)->schedule($mine, '2026-11-03 10:00', [$panel->getKey()]);
        app(Interviews::class)->schedule($theirs, '2026-11-04 10:00', [$panel->getKey()]);

        $list = Livewire::test(ListInterviews::class)->assertSuccessful();

        $this->assertSame(
            'application.applicant.name',
            $list->instance()->getTableGrouping()?->getId(),
            'the list opens grouped by candidate rather than needing somebody to choose it',
        );

        // Grouped, the two people do not interleave: both of Ayesha's rounds come before Hamza's.
        $list->assertSeeInOrder(['Ayesha Noor', 'Hamza Iqbal']);
    }
}
