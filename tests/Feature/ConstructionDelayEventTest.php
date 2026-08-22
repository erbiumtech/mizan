<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionField\Console\Commands\CheckDelayNotices;
use App\Modules\ConstructionField\Filament\Resources\DelayEvents\Pages\ListDelayEvents;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Notifications\DelayNoticeDue;
use App\Modules\ConstructionField\Services\DelayEventService;
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
 * Delay events and the notice clock — §13, Phase 9a.
 *
 * **§13 says to build this before anything else in the phase, and gives the reason:** *"A due-date with a notification
 * attached is worth more commercially than the entire programme: a valid claim lost to a missed notice is the single
 * most common way a contractor donates money, and it fails in absolute silence."*
 *
 * That last clause is what this file is mostly about. Every other failure in this suite leaves a wrong figure somewhere
 * a report can find; this one leaves nothing — the event happened, nobody wrote inside the window, and the entitlement
 * is gone with no number to be wrong. So the tests below are about the clock, the warning, and the exposure list.
 *
 * Five properties:
 *
 *  - **The due date is computed once and frozen.** From the contract's period where there is one, from config where
 *    there is not — and a later edit to the contract cannot move a deadline somebody has been warned about.
 *  - **Time-barred is computed, never stored**, and judged as at a date so "was this claimable in June" stays
 *    answerable.
 *  - **The warning fires once per threshold**, tightening as the date approaches, and keeps firing once overdue.
 *  - **A late notice is recorded, not refused.** Whether lateness bars a claim is contractual; deleting the evidence
 *    is not an option.
 *  - **The module stands alone.** `construction_field` requires only `construction`, so all of this works with no
 *    contract, no cost ledger and no books.
 */
class ConstructionDelayEventTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private DelayEventService $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'delays@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->events = app(DelayEventService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function event(array $attributes = []): DelayEvent
    {
        return $this->events->raise($this->job, array_merge([
            'title' => 'Access to the west bay withheld',
            'cause_category' => 'access',
            'occurred_on' => '2026-08-01',
        ], $attributes));
    }

    // ------------------------------------------------------------------ the clock

    /**
     * **The exit condition of the sub-phase: raising an event computes and freezes the notice date.**
     *
     * 28 days from the event, which is FIDIC 20.1 and the shipped default. The number of days is stored beside the date
     * so the date can be explained rather than merely trusted.
     */
    public function test_raising_an_event_computes_and_freezes_the_notice_date(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);

        $this->assertSame('2026-08-29', $event->notice_required_by->toDateString());
        $this->assertSame(28, $event->notice_days);
        $this->assertSame(DelayEvent::STATUS_OPEN, $event->status);
        $this->assertSame('DE-1', $event->reference);
    }

    /** The contract's own period wins where the event names one — FIDIC's 28, NEC4's 56, a subcontract's 7. */
    public function test_the_contracts_own_notice_period_applies(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction_contracts'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $contract = app(ContractService::class)->create($this->job, [
            'side' => Contract::SIDE_RECEIVABLE,
            'title' => 'Main works',
            'contract_sum' => 100_000_000,
            'delay_notice_days' => 7,
        ]);

        $event = $this->event(['contract_id' => $contract->getKey(), 'occurred_on' => '2026-08-01']);

        $this->assertSame(7, $event->notice_days);
        $this->assertSame('2026-08-08', $event->notice_required_by->toDateString());
    }

    /**
     * **Editing the contract's period afterwards does not move a deadline already warned about.**
     *
     * The same reasoning §8 gives for freezing a certificate: a date somebody has been emailed cannot be allowed to
     * move underneath them.
     */
    public function test_changing_the_contract_period_does_not_move_an_existing_deadline(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction_contracts'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $contract = app(ContractService::class)->create($this->job, [
            'side' => Contract::SIDE_RECEIVABLE, 'title' => 'Main works',
            'contract_sum' => 100_000_000, 'delay_notice_days' => 28,
        ]);

        $event = $this->event(['contract_id' => $contract->getKey(), 'occurred_on' => '2026-08-01']);

        $contract->update(['delay_notice_days' => 3]);

        $this->assertSame('2026-08-29', $event->refresh()->notice_required_by->toDateString());
        $this->assertSame(28, $event->notice_days);
    }

    /** Days remaining is **signed**, so overdue is a different number from due-today rather than the same zero. */
    public function test_days_until_notice_is_signed(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);

        $this->assertSame(28, $event->daysUntilNoticeDue('2026-08-01'));
        $this->assertSame(3, $event->daysUntilNoticeDue('2026-08-26'));
        $this->assertSame(0, $event->daysUntilNoticeDue('2026-08-29'));
        $this->assertSame(-5, $event->daysUntilNoticeDue('2026-09-03'));
    }

    // ------------------------------------------------------------------ time-barred, computed

    /** Computed from the dates and judged as at a date, so "was this claimable in June" stays answerable. */
    public function test_time_barred_is_computed_as_at_a_date(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);

        $this->assertFalse($event->isTimeBarred('2026-08-29'), 'the last day is still in time');
        $this->assertTrue($event->isTimeBarred('2026-08-30'));
    }

    /** An event that was notified is never barred, however late the notice was. */
    public function test_a_notified_event_is_never_time_barred(): void
    {
        $event = $this->events->giveNotice($this->event(['occurred_on' => '2026-08-01']), '2026-09-15');

        $this->assertFalse($event->isTimeBarred('2026-12-31'));
        $this->assertTrue($event->noticeWasLate(), 'but the row says the notice was late');
    }

    /** Withdrawn and rejected events are not "barred" — they are closed, which is a different fact. */
    public function test_a_closed_event_is_not_reported_as_time_barred(): void
    {
        $event = $this->events->withdraw($this->event(['occurred_on' => '2026-08-01']), 'Access was given the next day.');

        $this->assertFalse($event->isTimeBarred('2026-12-31'));
    }

    /** The exposure list: money already lost, as a query rather than a habit. */
    public function test_the_time_barred_list_finds_what_was_missed(): void
    {
        $this->event(['occurred_on' => '2026-08-01']);
        $this->events->giveNotice($this->event(['occurred_on' => '2026-08-02']), '2026-08-10');

        $barred = $this->events->timeBarred('2026-09-30');

        $this->assertCount(1, $barred);
        $this->assertSame('DE-1', $barred->first()->reference);
    }

    // ------------------------------------------------------------------ the warning ladder

    /** The **tightest** threshold reached, not the loosest — the bug that would have skipped every warning but one. */
    public function test_the_warning_threshold_is_the_tightest_one_reached(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);

        $this->assertNull($event->warningThreshold('2026-08-01'), '28 days out is not near anything');
        $this->assertSame(14, $event->warningThreshold('2026-08-16'), '13 days left');
        $this->assertSame(7, $event->warningThreshold('2026-08-24'), '5 days left');
        $this->assertSame(3, $event->warningThreshold('2026-08-27'));
        $this->assertSame(1, $event->warningThreshold('2026-08-28'));
        $this->assertSame(0, $event->warningThreshold('2026-08-29'));
        $this->assertSame(0, $event->warningThreshold('2026-09-10'), 'overdue keeps warning at the last threshold');
    }

    /** Due for warning once per threshold, and not again until the answer changes. */
    public function test_a_warning_fires_once_per_threshold(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);

        $due = $this->events->dueForWarning('2026-08-24');
        $this->assertCount(1, $due);
        $this->assertSame(7, $due->first()['threshold']);
        $this->assertSame(5, $due->first()['days']);

        $this->events->markWarned($event, 7);

        $this->assertCount(0, $this->events->dueForWarning('2026-08-25'), 'same threshold, nothing new to say');
        $this->assertCount(1, $this->events->dueForWarning('2026-08-27'), 'the 3-day threshold is news');
    }

    /** A notified event drops off the warning list entirely: the clock has stopped. */
    public function test_a_notified_event_stops_warning(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);
        $this->events->giveNotice($event, '2026-08-10');

        $this->assertCount(0, $this->events->dueForWarning('2026-08-28'));
    }

    // ------------------------------------------------------------------ the nightly command

    /**
     * **The deliverable of the sub-phase: the silence is broken.**
     *
     * Mailed to whoever can serve the notice — `ConstructionDelayUpdate` — rather than to whoever determines claims,
     * because the second group can do nothing with it. The same call `CheckComplianceExpiry` makes.
     */
    public function test_the_command_warns_whoever_can_serve_the_notice(): void
    {
        Notification::fake();

        $clerk = $this->makeUser('Accountant', 'clerk@test.local');
        $this->event(['occurred_on' => '2026-08-01']);

        $this->runNoticeCommand('2026-08-27');

        // Sent to whoever can serve it. The Administrator in setUp holds the grant too, so the mail fans out — which
        // is the intent: anybody who can write the letter should know.
        Notification::assertSentTo($clerk, DelayNoticeDue::class);
    }

    /** And marks the threshold, so a second run the same week sends nothing. */
    public function test_the_command_does_not_repeat_itself(): void
    {
        Notification::fake();
        $clerk = $this->makeUser('Accountant', 'clerk2@test.local');

        $this->event(['occurred_on' => '2026-08-01']);

        // Counted per recipient, not in total: `Notification::send()` fans out to everybody holding the grant, so a
        // total count measures how many people hold it rather than how many times the event was reported.
        $this->runNoticeCommand('2026-08-27');
        Notification::assertSentToTimes($clerk, DelayNoticeDue::class, 1);

        $this->runNoticeCommand('2026-08-27');
        Notification::assertSentToTimes($clerk, DelayNoticeDue::class, 1);
    }

    /** An overdue event keeps warning, because the claim is not necessarily dead — but nobody has served anything. */
    public function test_the_command_warns_about_an_overdue_notice(): void
    {
        Notification::fake();
        $clerk = $this->makeUser('Accountant', 'clerk3@test.local');

        $this->event(['occurred_on' => '2026-08-01']);

        $this->runNoticeCommand('2026-09-05');

        Notification::assertSentToTimes($clerk, DelayNoticeDue::class, 1);
    }

    /** Nothing near a threshold means nothing sent, and the command says so rather than staying silent. */
    public function test_the_command_says_when_there_is_nothing_to_warn_about(): void
    {
        Notification::fake();
        $this->event(['occurred_on' => '2026-08-01']);

        $this->assertStringContainsString(
            'No delay event has newly crossed',
            $this->runNoticeCommand('2026-08-02'),
        );

        Notification::assertNothingSent();
    }

    /** Skipped for a company without the module, rather than erroring. */
    public function test_the_command_skips_a_company_without_the_module(): void
    {
        Notification::fake();
        $this->makeUser('Accountant', 'clerk4@test.local');
        $this->event(['occurred_on' => '2026-08-01']);

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'construction_field')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->runNoticeCommand('2026-08-27');

        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------ notice, particulars, determination

    /** Notice stops the clock and starts the particulars one, measured from the notice. */
    public function test_notice_starts_the_particulars_clock(): void
    {
        $event = $this->events->giveNotice($this->event(['occurred_on' => '2026-08-01']), '2026-08-10');

        $this->assertSame(DelayEvent::STATUS_NOTIFIED, $event->status);
        $this->assertSame('2026-08-10', $event->notice_given_on->toDateString());
        // 42 days from the notice, not from the event: the notice is the date the contractor controls.
        $this->assertSame('2026-09-21', $event->particulars_due_by->toDateString());
    }

    public function test_notice_cannot_be_served_twice(): void
    {
        $event = $this->events->giveNotice($this->event(), '2026-08-10');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already served');

        $this->events->giveNotice($event, '2026-08-11');
    }

    public function test_notice_cannot_predate_the_event(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-10']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('before the event happened');

        $this->events->giveNotice($event, '2026-08-01');
    }

    public function test_particulars_need_a_notice_first(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has not been served');

        $this->events->submitParticulars($this->event(), 12.0, 340_000.0);
    }

    public function test_particulars_record_what_is_claimed(): void
    {
        $event = $this->events->giveNotice($this->event(), '2026-08-10');
        $event = $this->events->submitParticulars($event, 12.0, 340_000.0, '2026-08-20');

        $this->assertSame(DelayEvent::STATUS_PARTICULARS_SUBMITTED, $event->status);
        $this->assertEquals(12, $event->claimed_days);
        $this->assertEquals(340_000, $event->cost_claimed);
    }

    /** A determination records the award and its grounds, and closes the event. */
    public function test_determining_awards_days_with_grounds(): void
    {
        $event = $this->events->submitParticulars(
            $this->events->giveNotice($this->event(), '2026-08-10'), 12.0, 340_000.0
        );

        $event = $this->events->determine($event, 8.0, 200_000.0, 'Eight days of the twelve were concurrent.');

        $this->assertSame(DelayEvent::STATUS_DETERMINED, $event->status);
        $this->assertEquals(8, $event->awarded_days);
        $this->assertNotNull($event->determined_by);
        $this->assertTrue($event->isDetermined());
    }

    /** **Awarding more than was claimed is refused**: that is a different event, and it needs its own notice. */
    public function test_awarding_more_than_was_claimed_is_refused(): void
    {
        $event = $this->events->submitParticulars(
            $this->events->giveNotice($this->event(), '2026-08-10'), 12.0
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than was asked for');

        $this->events->determine($event, 60.0, null, 'Generous.');
    }

    /** Awarding nothing is a rejection and says so — a determined event at zero days reads as an oversight. */
    public function test_awarding_nothing_is_recorded_as_a_rejection(): void
    {
        $event = $this->events->submitParticulars(
            $this->events->giveNotice($this->event(), '2026-08-10'), 12.0
        );

        $event = $this->events->determine($event, 0.0, null, 'Contractor-risk event under clause 8.4.');

        $this->assertSame(DelayEvent::STATUS_REJECTED, $event->status);
    }

    public function test_a_determination_needs_grounds(): void
    {
        $event = $this->events->giveNotice($this->event(), '2026-08-10');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->events->determine($event, 5.0, null, '  ');
    }

    public function test_a_determined_event_cannot_be_determined_again(): void
    {
        $event = $this->events->determine(
            $this->events->giveNotice($this->event(), '2026-08-10'), 5.0, null, 'Five days.'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already determined');

        $this->events->determine($event, 6.0, null, 'Again.');
    }

    // ------------------------------------------------------------------ raising, refusals, references

    public function test_an_event_needs_a_title_and_a_cause(): void
    {
        try {
            $this->events->raise($this->job, ['cause_category' => 'access', 'title' => '  ']);
            $this->fail('A nameless event should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a title', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cause category');

        $this->events->raise($this->job, ['title' => 'Something happened']);
    }

    /** References run per job, and a withdrawn one does not release its number. */
    public function test_references_run_per_job_without_reusing_numbers(): void
    {
        $first = $this->event();
        $second = $this->event();
        $this->events->withdraw($second, 'Duplicate.');

        $this->assertSame('DE-1', $first->reference);
        $this->assertSame('DE-2', $second->reference);
        $this->assertSame('DE-3', $this->event()->reference, 'the withdrawn number is not reused');

        $other = Job::create(['code' => 'J-2', 'name' => 'Annexe']);
        $this->assertSame('DE-1', $this->events->raise($other, [
            'title' => 'Late drawings', 'cause_category' => 'late_information',
        ])->reference);
    }

    /** Concurrency is recorded and never interpreted — §13's "whole argument in most disputes". */
    public function test_concurrency_is_recorded_as_a_fact(): void
    {
        $first = $this->event();
        $second = $this->event(['concurrent_with_delay_event_id' => $first->getKey()]);

        $this->assertSame($first->getKey(), $second->concurrentWith->getKey());
        // Nothing about entitlement follows from it, which is the point.
        $this->assertNull($second->awarded_days);
    }

    // ------------------------------------------------------------------ the module stands alone (§18)

    /**
     * The whole clock works with no contract, no cost ledger and no books.
     *
     * `construction_field` requires only `construction` (§18), and this is the assertion that makes the claim
     * structural rather than declared: the setUp above licenses exactly two modules.
     */
    public function test_everything_works_with_only_the_spine_and_this_module(): void
    {
        // Switched off explicitly: this tenant licenses the rest of the suite by default, so the absence has to be
        // arranged rather than assumed — the same trap Phase 7b hit with payroll.
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_contracts', 'construction_costing', 'accounting', 'inventory'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse(modules()->enabled('construction_contracts'));
        $this->assertFalse(modules()->enabled('construction_costing'));

        $event = $this->events->giveNotice($this->event(), '2026-08-10');

        $this->assertSame(28, $event->notice_days, 'the shipped default, with no contract to read');
        $this->assertSame(DelayEvent::STATUS_NOTIFIED, $event->status);
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_register_leads_with_the_clock(): void
    {
        $this->travelTo('2026-08-27');

        $event = $this->event(['occurred_on' => '2026-08-01']);

        Livewire::test(ListDelayEvents::class)
            ->assertCanSeeTableRecords([$event])
            ->assertSee('DE-1')
            ->assertSee('2d left');
    }

    /** An expired clock is unmissable on the register, which is the whole point of the column. */
    public function test_a_time_barred_event_is_marked_on_the_register(): void
    {
        $this->travelTo('2026-09-05');

        $event = $this->event(['occurred_on' => '2026-08-01']);

        Livewire::test(ListDelayEvents::class)
            ->assertCanSeeTableRecords([$event])
            ->assertSee('TIME-BARRED');
    }

    public function test_the_notice_action_stops_the_clock(): void
    {
        $event = $this->event(['occurred_on' => '2026-08-01']);

        Livewire::test(ListDelayEvents::class)
            ->callAction(TestAction::make('giveNotice')->table($event), ['notice_given_on' => '2026-08-10']);

        $this->assertTrue($event->refresh()->noticeGiven());
    }

    /** And a refusal reaches the user as a notification rather than a stack trace. */
    public function test_the_determine_action_surfaces_an_over_award(): void
    {
        $event = $this->events->submitParticulars(
            $this->events->giveNotice($this->event(), '2026-08-10'), 12.0
        );

        Livewire::test(ListDelayEvents::class)
            ->callAction(TestAction::make('determine')->table($event), [
                'awarded_days' => 60,
                'reason' => 'Generous.',
            ])
            ->assertNotified();

        $this->assertFalse($event->refresh()->isDetermined());
    }

    /**
     * Run the nightly command by calling `handle()` rather than through `artisan()`.
     *
     * The command is `TenantAware`, and going through the kernel makes it switch tenant databases — which this suite's
     * single-connection layout cannot do. The tenant is already current, which is the state the scheduler puts it in
     * anyway. `ConstructionComplianceTest` established this shape for the same reason.
     */
    private function runNoticeCommand(string $date): string
    {
        $command = new CheckDelayNotices;
        $command->setLaravel($this->app);

        $input = new ArrayInput([], new InputDefinition([
            new InputOption('date', null, InputOption::VALUE_OPTIONAL),
        ]));
        $input->setOption('date', $date);

        $buffer = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        $this->assertSame(0, $command->handle($this->events));

        return $buffer->fetch();
    }
}
