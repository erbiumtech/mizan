<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages\ListPermits;
use App\Modules\ConstructionQhse\Models\Permit;
use App\Modules\ConstructionQhse\Services\PermitService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Permits to work — §17.5, Phase 10e.
 *
 * **"A permit is time-boxed, and an expired-but-open permit is the failure mode that kills people."** Everything asserted
 * here follows from that sentence.
 *
 *  - **Both ends of the window are datetimes.** A permit valid "on the 20th" authorises hot work at four in the morning.
 *  - **An extension is a new row pointing back at the one it extends**, and a test asserts the original's `valid_to` is
 *    untouched. §17.5: "overwriting `valid_to` destroys the record of what was authorised when" — and a regulator asks
 *    what was authorised *at the moment something happened*.
 *  - **Issuing is refused for a window that has already closed**, and refused until the controls that type's procedure
 *    turns on are recorded.
 *  - **Nothing auto-closes an expired permit.** Expiry makes it visible; a person closes it.
 *  - **The close-out asks whether the area was made safe**, and closing without it needs a reason — because a permit that
 *    cannot be closed at all stays open for ever and the expired-and-open list becomes noise.
 */
class ConstructionPermitTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private PermitService $permits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'permit@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->permits = app(PermitService::class);
    }

    /** A hot-work permit with the two controls that type's procedure turns on. */
    private function permit(array $attributes = []): Permit
    {
        return $this->permits->draft($this->job, array_merge([
            'type' => 'hot_work',
            'description' => 'Welding handrail brackets on level 4, east elevation.',
            'valid_from' => now()->subHour()->toDateTimeString(),
            'valid_to' => now()->addHours(7)->toDateTimeString(),
            'requester_label' => 'Site agent',
            'persons_count' => 2,
            'details' => ['fire_watch' => 'A. Watcher', 'extinguisher_present' => 'CO2, 5 kg'],
        ], $attributes));
    }

    // ------------------------------------------------------------------ the window

    public function test_a_permit_is_numbered_and_starts_as_a_draft(): void
    {
        $permit = $this->permit();

        $this->assertSame('PTW-1', $permit->permit_number);
        $this->assertTrue($permit->isDraft());
        $this->assertTrue($permit->isOpen());
        $this->assertFalse($permit->isInForceAt(), 'a draft authorises nothing');
        $this->assertSame(8, $permit->windowHours());
    }

    public function test_a_permit_needs_a_type_a_description_and_both_ends_of_its_window(): void
    {
        foreach ([
            [['type' => 'sandwich_making'], 'needs a type'],
            [['description' => ' '], 'needs describing'],
            [['valid_from' => null], 'both ends of its window'],
            [['valid_to' => null], 'both ends of its window'],
            [['valid_to' => now()->subDay()->toDateTimeString()], 'cannot end before it starts'],
        ] as [$override, $expected]) {
            try {
                $this->permit($override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** **The times survive** — which is the difference between a permit and a note on a whiteboard. */
    public function test_the_window_keeps_its_times(): void
    {
        $permit = $this->permit([
            'valid_from' => '2026-08-20 07:00',
            'valid_to' => '2026-08-20 17:00',
        ]);

        $this->assertSame('2026-08-20 07:00', $permit->valid_from->format('Y-m-d H:i'));
        $this->assertSame('2026-08-20 17:00', $permit->valid_to->format('Y-m-d H:i'));
        $this->assertSame(10, $permit->windowHours());

        // And at four in the morning it authorises nothing, which a date column could not express.
        $this->assertFalse($permit->isInForceAt('2026-08-20 04:00'));
    }

    // ------------------------------------------------------------------ issuing

    /** **Issuing is the act that authorises work**, and it is refused for a window already closed. */
    public function test_a_permit_cannot_be_issued_for_a_window_that_has_closed(): void
    {
        $stale = $this->permit([
            'valid_from' => '2026-08-01 07:00',
            'valid_to' => '2026-08-01 17:00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorise work that is already over');

        $this->permits->issue($stale);
    }

    /**
     * **Refused until the controls that type's procedure turns on are recorded.**
     *
     * Checked at issue rather than at draft, because a permit half-written is the ordinary state of a draft.
     */
    public function test_issuing_is_refused_without_the_controls_the_type_turns_on(): void
    {
        $noFireWatch = $this->permit(['details' => ['extinguisher_present' => 'CO2, 5 kg']]);

        try {
            $this->permits->issue($noFireWatch);
            $this->fail('Hot work with no fire watch should not be issued.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('fire watch', $e->getMessage());
            $this->assertStringContainsString('a permit nobody thought about', $e->getMessage());
        }

        $confined = $this->permit([
            'type' => 'confined_space',
            'description' => 'Entry to the pump chamber.',
            'details' => ['gas_test' => 'O2 20.9%, LEL 0%'],
        ]);

        try {
            $this->permits->issue($confined);
            $this->fail('A confined space with no rescue plan should not be issued.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('rescue plan', $e->getMessage());
        }
    }

    public function test_issuing_records_who_and_when_and_puts_it_in_force(): void
    {
        $permit = $this->permits->issue($this->permit());

        $this->assertSame(Permit::STATUS_ISSUED, $permit->status);
        $this->assertNotNull($permit->issued_by);
        $this->assertNotNull($permit->issued_at);
        $this->assertTrue($permit->isInForceAt());
        $this->assertTrue($permit->issuedButNotAccepted(), 'nobody has taken it on yet');
    }

    /** A permit nobody accepted is a piece of paper rather than an authorisation. */
    public function test_acceptance_is_recorded_separately_and_needs_a_name(): void
    {
        $permit = $this->permits->issue($this->permit());

        try {
            $this->permits->accept($permit, '  ');
            $this->fail('An unnamed acceptance should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nobody is answerable for', $e->getMessage());
        }

        $accepted = $this->permits->accept($permit, 'B. Welder, Steel Co');

        $this->assertSame('B. Welder, Steel Co', $accepted->accepted_by_label);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertFalse($accepted->issuedButNotAccepted());
        $this->assertCount(0, $this->permits->issuedButNotAccepted($this->job));
    }

    /** An issued permit's window is what somebody signed for, so it cannot be edited. */
    public function test_an_issued_permit_cannot_be_edited(): void
    {
        $permit = $this->permits->issue($this->permit());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('record of what was authorised gets lost');

        $this->permits->update($permit, ['valid_to' => now()->addDays(3)->toDateTimeString()]);
    }

    // ------------------------------------------------------------------ expired and open

    /**
     * **The failure mode §17.5 names**, and nothing closes it automatically.
     */
    public function test_an_expired_permit_stays_open_until_somebody_closes_it(): void
    {
        // Issued while it was still live, then time passes — which is the only way a permit can legitimately become
        // expired-and-open, since issuing a closed window is refused.
        $permit = $this->permits->issue($this->permit([
            'valid_from' => now()->subHours(8)->toDateTimeString(),
            'valid_to' => now()->addHours(2)->toDateTimeString(),
        ]));

        $this->travel(3)->hours();
        $permit->refresh();

        $this->assertTrue($permit->isExpiredAndOpen());
        $this->assertFalse($permit->isInForceAt(), 'it authorises nothing now');
        $this->assertTrue($permit->isOpen(), 'and it is still somebody\'s responsibility');
        $this->assertCount(1, $this->permits->expiredAndOpen($this->job));

        // Closing it is the only thing that takes it off the list.
        $this->permits->close($permit, true, 'Area walked, no hot surfaces.');

        $this->assertCount(0, $this->permits->expiredAndOpen($this->job));
    }

    /** In force is computed, so it is never stale. */
    public function test_in_force_is_computed_at_the_moment_it_is_asked(): void
    {
        // Inside the window, because issuing a closed one is refused — and the point of the test is that the *answer*
        // is computed per question rather than stored.
        $this->travelTo('2026-08-20 08:00');

        $permit = $this->permits->issue($this->permit([
            'valid_from' => '2026-08-20 07:00',
            'valid_to' => '2026-08-20 17:00',
        ]));

        $this->assertTrue($permit->isInForceAt('2026-08-20 12:00'));
        $this->assertFalse($permit->isInForceAt('2026-08-20 18:00'));
        $this->assertFalse($permit->isInForceAt('2026-08-19 12:00'));

        $this->assertCount(1, $this->permits->inForce($this->job, '2026-08-20 12:00'));
        $this->assertCount(0, $this->permits->inForce($this->job, '2026-08-20 18:00'));
    }

    // ------------------------------------------------------------------ suspension

    /** Suspension is recorded with a reason and a time, because the history is what an investigation reads. */
    public function test_suspending_and_resuming_are_both_recorded(): void
    {
        $permit = $this->permits->issue($this->permit());

        try {
            $this->permits->suspend($permit, '  ');
            $this->fail('A suspension with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('before work restarts', $e->getMessage());
        }

        $suspended = $this->permits->suspend($permit, 'Wind above the 20 m/s limit.');

        $this->assertSame(Permit::STATUS_SUSPENDED, $suspended->status);
        $this->assertNotNull($suspended->suspended_at);
        $this->assertNotNull($suspended->suspended_by);
        $this->assertSame('Wind above the 20 m/s limit.', $suspended->suspension_reason);
        $this->assertFalse($suspended->isInForceAt(), 'a suspended permit authorises nothing');
        $this->assertTrue($suspended->isOpen());

        $resumed = $this->permits->resume($suspended);

        $this->assertSame(Permit::STATUS_ISSUED, $resumed->status);
        $this->assertNotNull($resumed->resumed_at);
        // The reason stays: a permit that was suspended once is a different history from one that never was.
        $this->assertSame('Wind above the 20 m/s limit.', $resumed->suspension_reason);
        $this->assertTrue($resumed->isInForceAt());
    }

    /** **Resuming after the window has closed is refused** — that is an extension, not a resumption. */
    public function test_resuming_after_the_window_closed_is_refused(): void
    {
        $permit = $this->permits->suspend(
            $this->permits->issue($this->permit([
                'valid_from' => now()->subHours(6)->toDateTimeString(),
                'valid_to' => now()->addMinutes(1)->toDateTimeString(),
            ])),
            'Wind.',
        );

        $this->travel(10)->minutes();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('extend it instead');

        $this->permits->resume($permit->refresh());
    }

    // ------------------------------------------------------------------ extension

    /**
     * **An extension is a new row, and the original's window is untouched.**
     *
     * The assertion §17.5 asks for: "overwriting `valid_to` destroys the record of what was authorised when."
     */
    public function test_extending_creates_a_new_permit_and_leaves_the_original_window_intact(): void
    {
        $this->travelTo('2026-08-20 08:00');

        $original = $this->permits->issue($this->permit([
            'valid_from' => '2026-08-20 07:00',
            'valid_to' => '2026-08-20 17:00',
        ]));

        $extension = $this->permits->extend($original, '2026-08-20 22:00', 'Pour ran late.');

        // The original still says exactly what it authorised.
        $original->refresh();
        $this->assertSame('2026-08-20 17:00', $original->valid_to->format('Y-m-d H:i'));
        $this->assertSame(Permit::STATUS_CLOSED, $original->status);
        $this->assertSame('2026-08-20 17:00', $original->closed_at->format('Y-m-d H:i'), 'closed at its own end');
        $this->assertFalse($original->area_made_safe, 'work continued, so nobody walked it');
        $this->assertStringContainsString('Extended by', $original->close_out_notes);

        // And the extension picks up exactly where it left off, so no minute is covered twice or not at all.
        $this->assertSame('PTW-1-EXT1', $extension->permit_number);
        $this->assertSame('2026-08-20 17:00', $extension->valid_from->format('Y-m-d H:i'));
        $this->assertSame('2026-08-20 22:00', $extension->valid_to->format('Y-m-d H:i'));
        $this->assertTrue($extension->isExtension());
        $this->assertSame($original->getKey(), $extension->extends_permit_id);
        // A draft, because extending is a request and issuing is still a decision.
        $this->assertTrue($extension->isDraft());
        // The controls travel, so the extension is not a permit with nothing recorded on it.
        $this->assertSame('A. Watcher', $extension->detail('fire_watch'));
    }

    public function test_an_extension_has_to_end_after_the_permit_it_extends(): void
    {
        $this->travelTo('2026-08-20 08:00');

        $permit = $this->permits->issue($this->permit([
            'valid_from' => '2026-08-20 07:00',
            'valid_to' => '2026-08-20 17:00',
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not an extension');

        $this->permits->extend($permit, '2026-08-20 12:00');
    }

    public function test_a_closed_permit_cannot_be_extended(): void
    {
        $permit = $this->permits->close($this->permits->issue($this->permit()), true, 'Done.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Raise a new permit');

        $this->permits->extend($permit, now()->addDay()->toDateTimeString());
    }

    // ------------------------------------------------------------------ close-out

    /** **The area made safe is the point of the close-out**, and closing without it needs a reason. */
    public function test_closing_without_the_area_made_safe_needs_a_reason(): void
    {
        $permit = $this->permits->issue($this->permit());

        try {
            $this->permits->close($permit, false);
            $this->fail('Closing with nothing recorded should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('burns a building down', $e->getMessage());
        }

        // Allowed with a reason, because a permit that cannot be closed stays open for ever.
        $closed = $this->permits->close($permit, false, 'Gang left site; area to be checked at 07:00.');

        $this->assertTrue($closed->isClosed());
        $this->assertFalse($closed->area_made_safe);
        $this->assertCount(1, $this->permits->closedWithoutMakingSafe($this->job));
    }

    public function test_closing_with_the_area_made_safe_needs_nothing_further(): void
    {
        $closed = $this->permits->close($this->permits->issue($this->permit()), true);

        $this->assertTrue($closed->area_made_safe);
        $this->assertNotNull($closed->closed_by);
        $this->assertCount(0, $this->permits->closedWithoutMakingSafe($this->job));
    }

    /** An extension's parent is excluded from that list: it closes without a walk by design. */
    public function test_an_extended_permit_is_not_counted_as_closed_without_making_safe(): void
    {
        $original = $this->permits->issue($this->permit());
        $this->permits->extend($original, now()->addDays(1)->toDateTimeString());

        $this->assertFalse($original->refresh()->area_made_safe);
        $this->assertCount(0, $this->permits->closedWithoutMakingSafe($this->job));
    }

    public function test_cancelling_needs_a_reason_and_settles_it(): void
    {
        $permit = $this->permit();

        try {
            $this->permits->cancel($permit, ' ');
            $this->fail('A cancel with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        $cancelled = $this->permits->cancel($permit, 'Work deferred to next week.');

        $this->assertSame(Permit::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->isClosed());
        $this->assertCount(0, $this->permits->expiredAndOpen($this->job, now()->addYear()->toDateTimeString()));
    }

    // ------------------------------------------------------------------ the indicator

    /** §17.6's leading indicator, and null rather than a flattering 100% where nothing has been closed. */
    public function test_the_closed_on_time_rate_is_null_until_something_is_closed(): void
    {
        $this->assertNull($this->permits->closedOnTimeRate($this->job));

        // One closed before its window ended.
        $onTime = $this->permits->issue($this->permit([
            'valid_from' => now()->subHours(2)->toDateTimeString(),
            'valid_to' => now()->addHours(4)->toDateTimeString(),
        ]));
        $this->permits->close($onTime, true);

        $this->assertSame(100.0, $this->permits->closedOnTimeRate($this->job));

        // And one issued live, left to expire, and closed the next morning.
        $late = $this->permits->issue($this->permit([
            'valid_from' => now()->subHours(6)->toDateTimeString(),
            'valid_to' => now()->addHour()->toDateTimeString(),
        ]));

        $this->travel(3)->hours();

        $this->permits->close($late->refresh(), true, 'Found open the next morning.');

        $this->assertSame(50.0, $this->permits->closedOnTimeRate($this->job));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_an_expired_permit_in_the_window_column(): void
    {
        $expired = $this->permits->issue($this->permit([
            'valid_from' => now()->subHours(8)->toDateTimeString(),
            'valid_to' => now()->addHours(2)->toDateTimeString(),
        ]));

        $this->travel(3)->hours();

        Livewire::test(ListPermits::class)
            ->assertCanSeeTableRecords([$expired])
            ->assertSee('PTW-1')
            ->assertSee('Hot work')
            ->assertSee('EXPIRED, still open')
            ->assertSee('not accepted');
    }

    public function test_the_screen_issues_then_closes_out(): void
    {
        $permit = $this->permit();

        Livewire::test(ListPermits::class)->callAction(TestAction::make('issue')->table($permit));

        $this->assertSame(Permit::STATUS_ISSUED, $permit->refresh()->status);

        Livewire::test(ListPermits::class)
            ->callAction(TestAction::make('close')->table($permit), [
                'area_made_safe' => true,
                'notes' => 'Walked at 17:10, no hot surfaces.',
            ]);

        $permit->refresh();

        $this->assertTrue($permit->isClosed());
        $this->assertTrue($permit->area_made_safe);
    }

    public function test_the_issue_action_surfaces_the_missing_control_refusal(): void
    {
        $permit = $this->permit(['details' => ['extinguisher_present' => 'CO2']]);

        Livewire::test(ListPermits::class)->callAction(TestAction::make('issue')->table($permit));

        $this->assertTrue($permit->refresh()->isDraft(), 'the refusal held');
    }

    public function test_the_screen_extends_a_permit(): void
    {
        $this->travelTo('2026-08-20 08:00');

        $permit = $this->permits->issue($this->permit([
            'valid_from' => '2026-08-20 07:00',
            'valid_to' => '2026-08-20 17:00',
        ]));

        Livewire::test(ListPermits::class)
            ->callAction(TestAction::make('extend')->table($permit), [
                'valid_to' => '2026-08-20 22:00',
                'reason' => 'Pour ran late.',
            ]);

        $extension = Permit::query()->whereNotNull('extends_permit_id')->firstOrFail();

        $this->assertSame('2026-08-20 17:00', $extension->valid_from->format('Y-m-d H:i'));
        $this->assertSame('2026-08-20 17:00', $permit->refresh()->valid_to->format('Y-m-d H:i'));
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['employees', 'invoicing', 'accounting', 'construction_contracts'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $permit = $this->permits->accept($this->permits->issue($this->permit()), 'B. Welder, Steel Co');

        $this->assertSame('B. Welder, Steel Co', $permit->accepted_by_label);
        $this->assertTrue($permit->isInForceAt());
        $this->assertTrue($this->permits->close($permit, true)->isClosed());
    }
}
