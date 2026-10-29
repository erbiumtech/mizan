<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Notifications\AnnouncementPublished;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Announcements — docs/saas-launch-plan.md §7.6. Publishing tells the
 * audience once; the dashboard shows each person what was aimed at them.
 */
class AnnouncementTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $it;

    private User $office;

    private User $noLogin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actingAs($this->makeUser('Administrator', 'hr@test.local'));
        $this->setCurrentTenant();

        $this->it = $this->makeUser('Employee', 'it@test.local');
        $this->office = $this->makeUser('Employee', 'office@test.local');

        Employee::create(['user_id' => $this->it->getKey(), 'employee_id' => 'EMP-1', 'name' => 'IT Person', 'gender' => 'Male', 'is_active' => true, 'department' => 'IT', 'date_of_joining' => '2020-01-01']);
        Employee::create(['user_id' => $this->office->getKey(), 'employee_id' => 'EMP-2', 'name' => 'Office Person', 'gender' => 'Female', 'is_active' => true, 'department' => 'Office Staff', 'date_of_joining' => '2020-01-01']);
        Employee::create(['employee_id' => 'EMP-3', 'name' => 'Floor Person', 'gender' => 'Male', 'is_active' => true, 'department' => 'IT', 'date_of_joining' => '2020-01-01']);
        Employee::create(['user_id' => $this->makeUser('Employee', 'left@test.local')->getKey(), 'employee_id' => 'EMP-4', 'name' => 'Left', 'gender' => 'Male', 'is_active' => false, 'department' => 'IT', 'date_of_joining' => '2020-01-01']);
    }

    public function test_publishing_to_everyone_tells_every_active_employee_with_a_login_once(): void
    {
        $announcement = Announcement::create(['title' => 'Eid holidays', 'body' => "Closed Monday and Tuesday.\n\nBack Wednesday."]);

        $this->assertFalse($announcement->isPublished());
        $told = $announcement->publish(auth()->user());

        $this->assertSame(2, $told, 'Everyone is the active employees with a login: not the leaver, not the one with no account.');
        $this->assertTrue($announcement->fresh()->isPublished());
        Notification::assertSentTo([$this->it, $this->office], AnnouncementPublished::class);
        Notification::assertNotSentTo(User::where('email', 'left@test.local')->first(), AnnouncementPublished::class);

        $this->expectException(InvalidArgumentException::class);
        $announcement->fresh()->publish(auth()->user());
    }

    public function test_a_department_announcement_reaches_that_department_and_the_dashboard_agrees(): void
    {
        $announcement = Announcement::create([
            'title' => 'Server move', 'body' => 'Saturday night.', 'audience_kind' => Announcement::AUDIENCE_DEPARTMENT, 'audience_value' => 'IT',
        ]);

        $this->assertSame(1, $announcement->publish(auth()->user()));
        Notification::assertSentTo($this->it, AnnouncementPublished::class);
        Notification::assertNotSentTo($this->office, AnnouncementPublished::class);

        $this->assertSame(['Server move'], Announcement::current()->forUser($this->it)->pluck('title')->all());
        $this->assertSame([], Announcement::current()->forUser($this->office)->pluck('title')->all());

        // A department announcement with no department named is refused, not sent to nobody.
        $blank = Announcement::create(['title' => 'x', 'body' => 'y', 'audience_kind' => Announcement::AUDIENCE_DEPARTMENT]);
        $this->expectException(InvalidArgumentException::class);
        $blank->publish(auth()->user());
    }

    public function test_the_dashboard_shows_only_what_is_published_and_not_expired_pinned_first(): void
    {
        Announcement::create(['title' => 'Draft', 'body' => 'x']);
        Announcement::create(['title' => 'Expired', 'body' => 'x', 'published_at' => now()->subDays(10), 'expires_at' => now()->subDay()->toDateString()]);
        Announcement::create(['title' => 'Old but live', 'body' => 'x', 'published_at' => now()->subDays(5)]);
        Announcement::create(['title' => 'Pinned', 'body' => 'x', 'pinned' => true, 'published_at' => now()->subDays(8), 'expires_at' => now()->addDay()->toDateString()]);
        Announcement::create(['title' => 'Newest', 'body' => 'x', 'published_at' => now()->subMinute()]);

        $this->assertSame(['Pinned', 'Newest', 'Old but live'], Announcement::current()->forUser($this->it)->pluck('title')->all());
    }

    public function test_employees_do_not_manage_announcements_and_managers_do(): void
    {
        $this->assertFalse($this->it->can('create', Announcement::class));
        $this->assertFalse($this->it->can('viewAny', Announcement::class));

        $manager = $this->makeUser('Manager', 'manager@test.local');
        $this->assertTrue($manager->can('create', Announcement::class));
        $this->assertTrue($manager->can('update', Announcement::create(['title' => 'x', 'body' => 'y'])));
        $this->assertFalse($manager->can('delete', Announcement::create(['title' => 'x', 'body' => 'y'])));
    }
}
