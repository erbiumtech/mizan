<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\Badge;
use App\Modules\Performance\Models\EmployeeBadge;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Services\Badges;
use App\Notifications\RecordChanged;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Badges — docs/saas-launch-plan.md §8.4. A manual award needs a reason and
 * `BadgeAward`; every award is one credit; locked badges are exactly the active
 * unearned ones. Rule-based awards arrive with the builder (§8.9).
 */
class BadgeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $manager;

    private User $login;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actingAs($this->makeUser('Administrator', 'hr-badges@test.local'));
        $this->setCurrentTenant();

        $this->manager = $this->makeUser('Manager', 'manager@test.local');
        $this->login = $this->makeUser('Employee', 'star@test.local');
        $this->employee = Employee::create(['user_id' => $this->login->getKey(), 'employee_id' => 'EMP-1', 'name' => 'Star', 'gender' => 'Female', 'is_active' => true, 'date_of_joining' => '2020-01-01']);

        Badge::create(['name' => 'Perfect attendance', 'description' => 'A month without a late mark', 'points' => 10]);
        Badge::create(['name' => 'Five years', 'description' => 'Five years of service', 'points' => 25]);
        Badge::create(['name' => 'Retired badge', 'points' => 5, 'is_active' => false]);
    }

    private function badges(): Badges
    {
        return app(Badges::class);
    }

    public function test_an_award_needs_a_reason_credits_the_ledger_and_tells_the_person(): void
    {
        $badge = Badge::where('name', 'Perfect attendance')->firstOrFail();

        try {
            $this->badges()->award($badge, $this->employee, '   ', $this->manager);
            $this->fail('Awarded with no reason.');
        } catch (InvalidArgumentException) {
        }

        $award = $this->badges()->award($badge, $this->employee, 'October without a single late mark', $this->manager);

        $this->assertSame($this->manager->getKey(), (int) $award->awarded_by);
        $this->assertSame(1, MeritEntry::count());
        $entry = MeritEntry::first();
        $this->assertSame(10, $entry->points);
        $this->assertSame(MeritEntry::SOURCE_BADGE, $entry->source_type);
        $this->assertSame($entry->getKey(), (int) $award->merit_entry_id);
        Notification::assertSentTo($this->login, RecordChanged::class);

        // Once per badge per person.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already holds');
        $this->badges()->award($badge, $this->employee, 'again', $this->manager);
    }

    public function test_locked_badges_are_exactly_the_active_unearned_ones(): void
    {
        $this->assertSame(['Five years', 'Perfect attendance'], $this->badges()->lockedFor($this->employee)->pluck('name')->all());

        $this->badges()->award(Badge::where('name', 'Five years')->firstOrFail(), $this->employee, 'Joined in 2020', $this->manager);

        $this->assertSame(['Perfect attendance'], $this->badges()->lockedFor($this->employee)->pluck('name')->all());
        $this->assertSame(['Five years'], $this->badges()->earnedBy($this->employee)->pluck('badge.name')->all());

        $this->expectException(InvalidArgumentException::class);
        $this->badges()->award(Badge::where('name', 'Retired badge')->firstOrFail(), $this->employee, 'x', $this->manager);
    }

    public function test_awarding_is_a_permission_of_its_own(): void
    {
        $this->assertTrue($this->manager->can('create', EmployeeBadge::class));
        $this->assertFalse($this->login->can('create', EmployeeBadge::class));
        $this->assertFalse($this->login->can('viewAny', Badge::class));
        $this->assertTrue($this->manager->can('viewAny', Badge::class));
        $this->assertFalse($this->manager->can('create', Badge::class), 'Managers award badges; Administrators define them.');
    }
}
