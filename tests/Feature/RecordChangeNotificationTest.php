<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Mpr\Models\MPR;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Notifications\RecordChanged;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The bell's general case — a record changing under the person it belongs to.
 *
 * Everything here is about *who* hears, because that is the whole difference between a bell worth looking at
 * and one people learn to ignore: the owner hears, the person who made the change does not, and a record
 * nobody owns tells nobody.
 */
class RecordChangeNotificationTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->editor = User::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($this->editor);
        $this->setCurrentTenant();

        foreach (['support', 'employees'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    private function mpr(): MPR
    {
        return MPR::create([
            'user_id' => $this->owner->getKey(),
            'mpr_date' => '2026-09-01',
            'feedback' => 'As written',
        ]);
    }

    public function test_the_owner_hears_when_somebody_else_changes_their_record(): void
    {
        $mpr = $this->mpr();

        Notification::fake();

        $mpr->update(['feedback' => 'Rewritten by somebody else']);

        Notification::assertSentTo($this->owner, RecordChanged::class);
        Notification::assertNotSentTo($this->editor, RecordChanged::class);
    }

    /** Told that you did what you just did, you learn to ignore the bell. */
    public function test_whoever_made_the_change_is_never_told_about_it(): void
    {
        $mine = MPR::create(['user_id' => $this->editor->getKey(), 'mpr_date' => '2026-09-01']);

        Notification::fake();

        $mine->update(['feedback' => 'My own note']);

        Notification::assertNothingSent();
    }

    /** Creations are excluded: their audience is the person who just made them, and an import makes thousands. */
    public function test_creating_a_record_notifies_nobody(): void
    {
        Notification::fake();

        $this->mpr();

        Notification::assertNothingSent();
    }

    public function test_a_deletion_reaches_the_owner_even_though_the_row_is_gone(): void
    {
        $mpr = $this->mpr();

        Notification::fake();

        $mpr->delete();

        // The audience came from the audit entry's copy of the row, since there is no row left to read.
        Notification::assertSentTo(
            $this->owner,
            fn (RecordChanged $notification): bool => $notification->isDeletion
                && str_contains($notification->title, 'deleted'),
        );
    }

    public function test_the_notification_names_who_changed_what(): void
    {
        $mpr = $this->mpr();

        Notification::fake();

        $mpr->update(['feedback' => 'Rewritten']);

        Notification::assertSentTo(
            $this->owner,
            fn (RecordChanged $notification): bool => str_contains($notification->body, 'Someone Else')
                && str_contains($notification->body, 'Feedback'),
        );
    }

    /** A model may name an audience the column rule cannot reach — here, the assignee's login. */
    public function test_a_model_can_name_its_own_audience(): void
    {
        $assigneeUser = User::factory()->create(['name' => 'Assignee']);
        $employee = Employee::create([
            'user_id' => $assigneeUser->getKey(),
            'employee_id' => 'EMP-A',
            'name' => 'Assignee',
            'gender' => 'Male',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'subject' => 'Printer is on fire',
            'assignee_employee_id' => $employee->getKey(),
        ]);

        Notification::fake();

        $ticket->update(['priority' => 'urgent']);

        Notification::assertSentTo($assigneeUser, RecordChanged::class);
    }

    public function test_a_company_can_switch_the_whole_thing_off(): void
    {
        app(TenantSettings::class)->set('notifications.record_changes', false);

        $mpr = $this->mpr();

        Notification::fake();

        $mpr->update(['month' => '2027-01']);

        Notification::assertNothingSent();
    }

    /** A record naming nobody tells nobody, rather than telling everybody. */
    public function test_a_record_with_no_owner_notifies_nobody(): void
    {
        // A ticket category carries no owner column at all — the ordinary case for reference data, and the
        // one where a rule that guessed would put an edit to a shared list in front of the whole company.
        $category = TicketCategory::create(['name' => 'Billing']);

        Notification::fake();

        $category->update(['name' => 'Billing queries']);

        Notification::assertNothingSent();
    }
}
