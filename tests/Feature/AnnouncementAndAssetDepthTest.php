<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Notifications\AnnouncementPublished;
use App\Modules\Lifecycle\Models\IssuedAsset;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Announcements and issued assets, deeper — docs/saas-launch-plan.md §14.10.
 *
 * The judgements worth pinning: that a scheduled announcement is not told twice, that
 * a role audience reads the roles somebody holds rather than a list, and that
 * archiving is not deleting.
 */
class AnnouncementAndAssetDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $manager;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // A TenantAware command switches tenant, and the suite's single database has no
        // tenant connection to switch to — so the switch is narrowed to the one task that
        // means anything here. Same idiom as AttendanceDepthTest.
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->actingAs($this->makeUser('Administrator', 'depth-hr@test.local'));
        $this->setCurrentTenant();

        $this->manager = $this->makeUser('Manager', 'depth-manager@test.local');
        $this->clerk = $this->makeUser('Employee', 'depth-clerk@test.local');

        Employee::create(['user_id' => $this->manager->getKey(), 'employee_id' => 'EMP-1', 'name' => 'A Manager', 'gender' => 'Male', 'is_active' => true, 'department' => 'IT']);
        Employee::create(['user_id' => $this->clerk->getKey(), 'employee_id' => 'EMP-2', 'name' => 'A Clerk', 'gender' => 'Female', 'is_active' => true, 'department' => 'Office Staff']);
    }

    // ------------------------------------------------------------- audiences

    public function test_a_role_audience_reaches_whoever_holds_the_role_today(): void
    {
        $announcement = Announcement::create([
            'title' => 'Approvals change on Monday',
            'body' => 'Read it.',
            'audience_kind' => Announcement::AUDIENCE_ROLE,
            'audience_value' => 'Manager',
        ]);

        $this->assertSame(1, $announcement->publish(auth()->user()));

        Notification::assertSentTo($this->manager, AnnouncementPublished::class);
        Notification::assertNotSentTo($this->clerk, AnnouncementPublished::class);
        $this->assertStringContainsString('Manager', $announcement->audienceLabel());
    }

    public function test_a_person_audience_reaches_one_person(): void
    {
        $employee = Employee::where('user_id', $this->clerk->getKey())->sole();

        $announcement = Announcement::create([
            'title' => 'About your desk',
            'body' => 'It is moving.',
            'audience_kind' => Announcement::AUDIENCE_PERSON,
            'audience_value' => (string) $employee->getKey(),
        ]);

        $this->assertSame(1, $announcement->publish(auth()->user()));

        Notification::assertSentTo($this->clerk, AnnouncementPublished::class);
        Notification::assertNotSentTo($this->manager, AnnouncementPublished::class);
    }

    public function test_somebody_sees_an_announcement_aimed_at_their_role(): void
    {
        $forManagers = Announcement::create(['title' => 'Managers', 'body' => '.', 'audience_kind' => Announcement::AUDIENCE_ROLE, 'audience_value' => 'Manager']);
        $forManagers->publish(auth()->user());

        $this->assertSame(
            ['Managers'],
            Announcement::current()->forUser($this->manager)->pluck('title')->all(),
        );
        $this->assertSame([], Announcement::current()->forUser($this->clerk)->pluck('title')->all());
    }

    // ------------------------------------------------------------ scheduling

    public function test_a_future_date_schedules_rather_than_publishing(): void
    {
        $announcement = Announcement::create([
            'title' => 'The office closes on Friday',
            'body' => '.',
            'published_at' => now()->addDays(3),
        ]);

        $this->assertSame(0, $announcement->publish(auth()->user()), 'nobody is told yet');
        Notification::assertNothingSent();

        $this->assertSame(
            [],
            Announcement::current()->pluck('title')->all(),
            'and it is not on the dashboard until its day',
        );
    }

    public function test_the_command_tells_the_audience_once_when_the_date_arrives(): void
    {
        $announcement = Announcement::create(['title' => 'Due now', 'body' => '.', 'published_at' => now()->subHours(2)]);

        $this->artisan('announcements:publish', ['--tenant' => [$this->tenant->getKey()]])->assertSuccessful();

        Notification::assertSentTo([$this->manager, $this->clerk], AnnouncementPublished::class);
        $this->assertSame(['Due now'], Announcement::current()->pluck('title')->all());

        // Two days later the window has moved past it, which is what stops a second
        // telling without a second column recording that it had been told.
        $this->travel(2)->days();
        Notification::fake();

        $this->artisan('announcements:publish', ['--tenant' => [$this->tenant->getKey()]])->assertSuccessful();
        Notification::assertNothingSent();

        $this->assertNotNull($announcement->refresh()->published_at);
    }

    // -------------------------------------------------------------- archiving

    public function test_archiving_takes_it_off_the_dashboard_and_deletes_nothing(): void
    {
        $announcement = Announcement::create(['title' => 'Old news', 'body' => '.']);
        $announcement->publish(auth()->user());

        $this->assertSame(['Old news'], Announcement::current()->pluck('title')->all());

        $announcement->forceFill(['archived_at' => now()])->save();

        $this->assertSame([], Announcement::current()->pluck('title')->all());
        $this->assertTrue($announcement->refresh()->isArchived());
        $this->assertTrue(Announcement::whereKey($announcement->getKey())->exists(), 'it is filed, not deleted');
    }

    public function test_an_archived_announcement_is_never_told_about_by_the_command(): void
    {
        Announcement::create(['title' => 'Filed', 'body' => '.', 'published_at' => now()->subHour(), 'archived_at' => now()]);

        $this->artisan('announcements:publish', ['--tenant' => [$this->tenant->getKey()]])->assertSuccessful();

        Notification::assertNothingSent();
    }

    // ----------------------------------------------------------------- assets

    public function test_an_asset_carries_a_tag_a_vendor_and_a_warranty(): void
    {
        $employee = Employee::where('user_id', $this->clerk->getKey())->sole();

        $asset = IssuedAsset::create([
            'employee_id' => $employee->getKey(),
            'asset_kind' => 'laptop',
            'description' => 'ThinkPad T14',
            'serial_no' => 'PF-0099',
            'tag' => 'MZN-IT-014',
            'vendor' => 'Galaxy Computers',
            'warranty_until' => '2027-06-30',
            'issued_on' => '2026-01-10',
        ]);

        $this->assertSame('MZN-IT-014', $asset->tag, 'the sticker, which is not the serial');
        $this->assertTrue($asset->isUnderWarranty('2026-08-20'));
        $this->assertFalse($asset->isUnderWarranty('2027-08-20'));
    }

    public function test_an_asset_with_no_warranty_recorded_answers_neither_way(): void
    {
        $employee = Employee::where('user_id', $this->clerk->getKey())->sole();

        $asset = IssuedAsset::create([
            'employee_id' => $employee->getKey(),
            'asset_kind' => 'phone',
            'description' => 'A phone',
            'issued_on' => '2026-01-10',
        ]);

        $this->assertNull($asset->isUnderWarranty(), 'nobody recorded it, so there is nothing to say');
    }

    public function test_the_asset_kinds_come_from_the_editable_list(): void
    {
        $options = IssuedAsset::kindOptions();

        $this->assertArrayHasKey('laptop', $options);
        $this->assertSame('SIM', $options['sim'] ?? null, 'the labels are the list\'s, not ucfirst of the value');
    }
}
