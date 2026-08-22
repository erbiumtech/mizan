<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Console\Commands\CheckCompetencyExpiry;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages\ListSitePersonnel;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages\EditToolboxTalk;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\RelationManagers\AttendeesRelationManager;
use App\Modules\ConstructionQhse\Models\SitePersonnel;
use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use App\Modules\ConstructionQhse\Notifications\CompetencyExpiring;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The induction register, competencies and toolbox talks — §17.5, Phase 10f.
 *
 * **A name is all that is ever required**, of somebody on the register and of an attendee at a talk. §17.5: "most
 * attendees on most sites are a subcontractor's labourers", and a register that asked for more would list the people who
 * happened to be on the payroll.
 *
 * Four more properties:
 *
 *  - **An induction is not permanent**, and never-inducted is kept apart from lapsed because they are different
 *    conversations.
 *  - **`is_mandatory` turns an expiry into a stoppage**, which is what `isClearedToWork()` reads.
 *  - **Warnings fire once per threshold**, and renewing clears the ladder so the ticket can warn again next year.
 *  - **Talks count attendance, not talks.** Forty talks to two people each is not a briefed site.
 */
class ConstructionSitePersonnelTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private SitePersonnelService $personnel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'personnel@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->personnel = app(SitePersonnelService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function person(array $attributes = []): SitePersonnel
    {
        return $this->personnel->register($this->job, array_merge([
            'name' => 'A. Labourer',
            'employer' => 'Scaffolding Co',
            'trade' => 'Scaffolder',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function talk(array $attributes = []): ToolboxTalk
    {
        return $this->personnel->recordTalk($this->job, array_merge([
            'topic' => 'Working at height — harness inspection',
            'delivered_at' => '2026-08-20 07:15',
            'presenter_label' => 'Site agent',
        ], $attributes));
    }

    // ------------------------------------------------------------------ a name is enough

    public function test_a_name_is_the_only_required_field(): void
    {
        $person = $this->person();

        $this->assertSame('A. Labourer (Scaffolding Co)', $person->displayName());
        $this->assertNull($person->employee_id);
        $this->assertNull($person->contact_id);
        $this->assertTrue($person->is_active);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a name is all that is required');

        $this->person(['name' => '  ']);
    }

    // ------------------------------------------------------------------ the induction

    /** **An induction is not permanent**, and the register distinguishes the two ways of not having one. */
    public function test_never_inducted_and_lapsed_are_different_states(): void
    {
        $never = $this->person(['name' => 'Never Inducted']);
        $lapsed = $this->personnel->induct(
            $this->person(['name' => 'Lapsed']),
            '2025-06-01',
            '2026-05-31',
        );
        $current = $this->personnel->induct(
            $this->person(['name' => 'Current']),
            '2026-06-01',
            '2027-05-31',
        );

        $this->assertFalse($never->isInducted());
        $this->assertFalse($never->inductionLapsed(), 'never inducted is not lapsed');

        $this->assertFalse($lapsed->isInducted('2026-08-20'));
        $this->assertTrue($lapsed->inductionLapsed('2026-08-20'));

        $this->assertTrue($current->isInducted('2026-08-20'));

        $this->assertSame(['Never Inducted'], $this->personnel->neverInducted($this->job)->pluck('name')->all());
        $this->assertSame(['Lapsed'], $this->personnel->inductionLapsed($this->job, '2026-08-20')->pluck('name')->all());
    }

    /** An induction with no expiry is a claim about site policy, and it is honoured. */
    public function test_an_induction_with_no_expiry_never_lapses(): void
    {
        $person = $this->personnel->induct($this->person(), '2020-01-01');

        $this->assertTrue($person->isInducted('2030-01-01'));
        $this->assertNull($person->induction_valid_to);
        $this->assertSame('2020-01-01', $person->first_on_site->toDateString(), 'seeded from the induction');
    }

    public function test_an_induction_cannot_expire_before_it_was_given(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot expire before it was given');

        $this->personnel->induct($this->person(), '2026-08-20', '2026-08-01');
    }

    /**
     * **Induction coverage is null on an empty register**, not 100%.
     *
     * §17.6's complaint about flattering figures: a site with nobody on the register is not fully inducted.
     */
    public function test_induction_coverage_is_null_with_nobody_on_the_register(): void
    {
        $this->assertSame(
            ['on_site' => 0, 'inducted' => 0, 'percent' => null],
            $this->personnel->inductionCoverage($this->job),
        );

        $this->personnel->induct($this->person(['name' => 'One']), '2026-06-01', '2027-05-31');
        $this->person(['name' => 'Two']);

        $coverage = $this->personnel->inductionCoverage($this->job, '2026-08-20');

        $this->assertSame(2, $coverage['on_site']);
        $this->assertSame(1, $coverage['inducted']);
        $this->assertSame(50.0, $coverage['percent']);
    }

    // ------------------------------------------------------------------ mandatory tickets

    /** **`is_mandatory` is what turns an expiry into a stoppage.** */
    public function test_a_lapsed_mandatory_ticket_stops_somebody_being_cleared(): void
    {
        $person = $this->personnel->induct($this->person(), '2026-06-01', '2027-05-31');

        $optional = $this->personnel->addCompetency($person, [
            'kind' => 'training', 'title' => 'First aid at work',
            'expires_on' => '2026-07-01', 'is_mandatory' => false,
        ]);

        $person->refresh()->load('competencies');
        $this->assertTrue($optional->hasExpired('2026-08-20'));
        $this->assertTrue($person->isClearedToWork('2026-08-20'), 'a lapsed optional ticket is a gap, not a stoppage');

        $mandatory = $this->personnel->addCompetency($person, [
            'kind' => 'certification', 'title' => 'Confined space entry',
            'reference' => 'CS-4412', 'issuing_body' => 'City & Guilds',
            'expires_on' => '2026-07-15', 'is_mandatory' => true,
        ]);

        $person->refresh()->load('competencies');

        $this->assertFalse($person->isClearedToWork('2026-08-20'));
        $this->assertCount(1, $person->expiredMandatoryCompetencies('2026-08-20'));
        $this->assertSame('Confined space entry (CS-4412)', $mandatory->displayName());

        $notCleared = $this->personnel->notCleared($this->job, '2026-08-20');
        $this->assertCount(1, $notCleared);
        $this->assertSame($person->getKey(), $notCleared->first()->getKey());
    }

    /** Somebody off site has historical tickets rather than urgent ones. */
    public function test_taking_somebody_off_site_takes_them_off_the_not_cleared_list(): void
    {
        $person = $this->personnel->induct($this->person(), '2026-06-01', '2027-05-31');
        $this->personnel->addCompetency($person, [
            'title' => 'Confined space entry', 'expires_on' => '2026-07-15', 'is_mandatory' => true,
        ]);

        $this->assertCount(1, $this->personnel->notCleared($this->job, '2026-08-20'));

        $this->personnel->deactivate($person->refresh(), '2026-08-19');

        $this->assertCount(0, $this->personnel->notCleared($this->job, '2026-08-20'));
        $this->assertFalse($person->refresh()->is_active);
        $this->assertSame('2026-08-19', $person->last_on_site->toDateString());
    }

    /** A ticket with no expiry never lapses, which is a real and common state. */
    public function test_a_ticket_with_no_expiry_never_lapses(): void
    {
        // An induction with no expiry too, so the assertion is about the *ticket* rather than about the induction
        // lapsing underneath it — which is what a first draft of this test actually measured.
        $person = $this->personnel->induct($this->person(), '2026-06-01');
        $ticket = $this->personnel->addCompetency($person, [
            'kind' => 'licence', 'title' => 'Driving licence', 'is_mandatory' => true,
        ]);

        $this->assertTrue($ticket->neverExpires());
        $this->assertFalse($ticket->hasExpired('2030-01-01'));
        $this->assertNull($ticket->daysUntilExpiry());
        $this->assertTrue($person->refresh()->load('competencies')->isClearedToWork('2030-01-01'));
    }

    public function test_a_competency_needs_a_title_and_a_known_kind_and_a_sane_pair_of_dates(): void
    {
        $person = $this->person();

        foreach ([
            [['title' => ' '], 'needs a title'],
            [['title' => 'X', 'kind' => 'vibes'], 'needs a kind'],
            [['title' => 'X', 'issued_on' => '2026-08-01', 'expires_on' => '2026-07-01'], 'expire before it was issued'],
        ] as [$attributes, $expected]) {
            try {
                $this->personnel->addCompetency($person, $attributes);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ the warning ladder

    /** **Once per threshold**, tightest first — the bug Phase 9a made and this suite now guards against twice. */
    public function test_the_warning_ladder_fires_once_per_threshold_tightest_first(): void
    {
        $person = $this->person();
        $ticket = $this->personnel->addCompetency($person, [
            'title' => 'Confined space entry', 'expires_on' => '2026-10-01', 'is_mandatory' => true,
        ]);

        // 41 days out: the 60-day threshold is the loosest one crossed, so that is what fires first.
        $this->assertSame(60, $ticket->warningThreshold('2026-08-21'));

        $this->personnel->markWarned($ticket, 60);

        $this->assertNull($ticket->refresh()->warningThreshold('2026-08-21'), 'already warned at 60');

        // 25 days out: 30 is now the tightest unwarned one.
        $this->assertSame(30, $ticket->warningThreshold('2026-09-06'));

        $this->personnel->markWarned($ticket, 30);

        // 5 days out: it skips straight to 7, not back to 14.
        $this->assertSame(7, $ticket->refresh()->warningThreshold('2026-09-26'));
    }

    /** **Renewing clears the ladder**, so the ticket can warn again next year. */
    public function test_renewing_clears_the_warning_history(): void
    {
        $person = $this->person();
        $ticket = $this->personnel->addCompetency($person, [
            'title' => 'Confined space entry', 'expires_on' => '2026-09-01', 'is_mandatory' => true,
        ]);
        $this->personnel->markWarned($ticket, 14);

        $this->assertSame(14, $ticket->refresh()->expiry_notified_at_days);

        $renewed = $this->personnel->renewCompetency($ticket, '2027-09-01', '2026-08-20');

        $this->assertNull($renewed->expiry_notified_at_days, 'so it can warn again next year');
        $this->assertSame('2027-09-01', $renewed->expires_on->toDateString());
        $this->assertFalse($renewed->hasExpired('2026-08-20'));
    }

    /** Only people still on site are chased. */
    public function test_only_active_personnel_are_due_for_warning(): void
    {
        $person = $this->person();
        $this->personnel->addCompetency($person, [
            'title' => 'Confined space entry', 'expires_on' => '2026-08-25', 'is_mandatory' => true,
        ]);

        $this->assertCount(1, $this->personnel->dueForWarning('2026-08-20'));

        $this->personnel->deactivate($person->refresh());

        $this->assertCount(0, $this->personnel->dueForWarning('2026-08-20'));
    }

    /**
     * The daily command warns, marks, and does not warn twice.
     *
     * Run by hand rather than through `artisan()`, following `ConstructionComplianceTest`'s pattern: a `TenantAware`
     * command cannot be dispatched that way in this suite.
     */
    public function test_the_daily_command_warns_once_and_records_that_it_did(): void
    {
        Notification::fake();

        $clerk = $this->makeUser('Manager', 'safety@test.local');

        $person = $this->personnel->induct($this->person(), '2026-06-01', '2027-05-31');
        $ticket = $this->personnel->addCompetency($person, [
            'title' => 'Confined space entry', 'expires_on' => '2026-08-25', 'is_mandatory' => true,
        ]);

        $this->runCommand('2026-08-20');

        Notification::assertSentToTimes($clerk, CompetencyExpiring::class, 1);
        $this->assertSame(7, $ticket->refresh()->expiry_notified_at_days);

        // The same day again sends nothing: the threshold is recorded.
        $this->runCommand('2026-08-20');

        Notification::assertSentToTimes($clerk, CompetencyExpiring::class, 1);
    }

    /**
     * `handle()` directly rather than through `artisan()`.
     *
     * The command is `TenantAware`, and running it through the kernel makes it switch tenant databases, which this
     * suite's connection layout cannot do — the same reason and the same shape as `ConstructionComplianceTest`'s runner.
     * The tenant is already current, which is the state the scheduler puts it in anyway.
     */
    private function runCommand(string $date): void
    {
        $command = new CheckCompetencyExpiry;
        $command->setLaravel($this->app);

        $input = new ArrayInput(['--date' => $date], new InputDefinition([
            new InputOption('date', null, InputOption::VALUE_OPTIONAL),
        ]));
        $buffer = new BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        $this->assertSame(0, $command->handle($this->personnel));
    }

    // ------------------------------------------------------------------ toolbox talks

    public function test_a_talk_needs_a_topic_and_a_time(): void
    {
        foreach ([
            [['topic' => ' '], 'needs a topic'],
            [['delivered_at' => null], 'needs the time it was given'],
        ] as [$override, $expected]) {
            try {
                $this->talk($override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $talk = $this->talk();

        $this->assertSame('TBT-1', $talk->reference);
        $this->assertSame('2026-08-20 07:15', $talk->delivered_at->format('Y-m-d H:i'));
    }

    /** **Attendance is the figure**, and a name alone is enough to record somebody. */
    public function test_an_attendee_may_be_a_register_entry_or_just_a_name(): void
    {
        $talk = $this->talk();
        $registered = $this->person(['name' => 'On The Register']);

        $fromRegister = $this->personnel->addAttendee($talk, ['site_personnel_id' => $registered->getKey()]);
        $justAName = $this->personnel->addAttendee($talk, ['name' => 'B. Passerby', 'employer' => 'Groundworks Co']);

        // The name is snapshotted even when the register is linked.
        $this->assertSame('On The Register', $fromRegister->name);
        $this->assertSame('Scaffolding Co', $fromRegister->employer);
        $this->assertTrue($fromRegister->isOnTheRegister());
        $this->assertFalse($justAName->isOnTheRegister());

        $talk->refresh()->load('attendees');
        $this->assertSame(2, $talk->attendeeCount());
        $this->assertFalse($talk->hasNoAttendees());

        // And renaming the register entry does not rewrite who was at the talk.
        $registered->update(['name' => 'Renamed Entirely']);
        $this->assertSame('On The Register', $fromRegister->refresh()->name);
    }

    public function test_an_attendee_needs_a_name_from_somewhere(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a name');

        $this->personnel->addAttendee($this->talk(), ['employer' => 'Groundworks Co']);
    }

    /** **Add everybody** is idempotent, which is what makes it safe to press twice at the gate. */
    public function test_adding_the_whole_site_skips_anybody_already_recorded(): void
    {
        $talk = $this->talk();
        $this->person(['name' => 'One']);
        $this->person(['name' => 'Two']);
        $offSite = $this->person(['name' => 'Gone']);
        $this->personnel->deactivate($offSite);

        $this->assertSame(2, $this->personnel->addWholeSite($talk), 'the one who left is not on site');
        $this->assertSame(0, $this->personnel->addWholeSite($talk->refresh()), 'pressing it twice adds nobody');
        $this->assertSame(2, $talk->refresh()->load('attendees')->attendeeCount());
    }

    /**
     * **Talks and attendances, both** — §17.6's indicator. Forty talks to two people each is not a briefed site.
     */
    public function test_talk_coverage_counts_attendance_as_well_as_talks(): void
    {
        $this->assertSame(
            ['talks' => 0, 'attendances' => 0, 'average' => null, 'unrecorded' => 0],
            $this->personnel->talkCoverage($this->job, '2026-08-01', '2026-08-31'),
        );

        $wellAttended = $this->talk();
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $this->personnel->addAttendee($wellAttended, ['name' => $name]);
        }

        // A talk given and not written up — a paperwork gap rather than an attendance one.
        $this->talk(['topic' => 'Manual handling', 'delivered_at' => '2026-08-21 07:15']);

        // And one in a different month, which is not this period's business.
        $this->talk(['topic' => 'Old news', 'delivered_at' => '2026-07-10 07:15']);

        $coverage = $this->personnel->talkCoverage($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(2, $coverage['talks']);
        $this->assertSame(4, $coverage['attendances']);
        $this->assertSame(2.0, $coverage['average']);
        $this->assertSame(1, $coverage['unrecorded']);
    }

    /** A talk raised in response to something is a different fact from a routine one. */
    public function test_a_prompted_talk_is_distinguishable_from_a_routine_one(): void
    {
        $routine = $this->talk();
        $prompted = $this->talk([
            'topic' => 'Scaffold boards — tying and inspection',
            'delivered_at' => '2026-08-21 07:15',
            'prompted_by' => 'Near miss on level 3 yesterday',
        ]);

        $this->assertFalse($routine->wasPrompted());
        $this->assertTrue($prompted->wasPrompted());
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_who_is_not_cleared(): void
    {
        $person = $this->personnel->induct($this->person(), '2025-06-01', '2026-05-31');

        Livewire::test(ListSitePersonnel::class)
            ->assertCanSeeTableRecords([$person])
            ->assertSee('A. Labourer')
            ->assertSee('induction lapsed');
    }

    public function test_the_screen_inducts_somebody(): void
    {
        $person = $this->person();

        Livewire::test(ListSitePersonnel::class)
            ->callAction(TestAction::make('induct')->table($person), [
                'on' => '2026-08-20',
                'valid_to' => '2027-08-19',
            ]);

        $person->refresh();

        $this->assertTrue($person->isInducted('2026-08-21'));
        $this->assertSame('2027-08-19', $person->induction_valid_to->toDateString());
    }

    public function test_the_attendees_tab_adds_the_whole_site(): void
    {
        $talk = $this->talk();
        $this->person(['name' => 'One']);
        $this->person(['name' => 'Two']);

        Livewire::test(AttendeesRelationManager::class, [
            'ownerRecord' => $talk,
            'pageClass' => EditToolboxTalk::class,
        ])
            ->callAction(TestAction::make('addWholeSite')->table());

        $this->assertSame(2, $talk->refresh()->load('attendees')->attendeeCount());
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['employees', 'invoicing', 'accounting', 'construction_field'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $person = $this->personnel->induct($this->person(), '2026-06-01', '2027-05-31');
        $this->personnel->addCompetency($person, [
            'title' => 'Scaffold inspection', 'expires_on' => '2027-01-01', 'is_mandatory' => true,
        ]);

        // The name is the whole of it, which is §17.5's requirement rather than a fallback.
        $this->assertSame('A. Labourer (Scaffolding Co)', $person->displayName());
        $this->assertTrue($person->refresh()->load('competencies')->isClearedToWork('2026-08-20'));

        $talk = $this->talk();
        $this->assertSame(1, $this->personnel->addWholeSite($talk));
    }
}
