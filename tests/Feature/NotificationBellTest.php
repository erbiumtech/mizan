<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Notifications\RecordChanged;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Broadcasting;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The count on the bell.
 *
 * Filament draws it — `:badge="$unreadNotificationsCount ?: null"` on the topbar trigger — which is why
 * there is no code of ours under test here and every assertion is about the panel as assembled. That is the
 * point: "the bell shows no count" is reported as a missing feature and is almost never one, so this pins
 * the three things that actually decide whether a number appears, and each of them is somewhere else.
 */
class NotificationBellTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function signIn(): User
    {
        // The panel's own chrome asks about permissions by name, and Spatie throws on a name with no row —
        // so a page render needs the seeded set even when the gate is open.
        $this->seed(PermissionSeeder::class);
        Gate::before(fn () => true);

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->setCurrentTenant();

        return $user;
    }

    /** The panel as a browser gets it: the topbar, and whatever the bell is carrying. */
    private function panelHtml(): string
    {
        return $this->get(Filament::getPanel('admin')->getUrl($this->tenant))
            ->assertOk()
            ->getContent();
    }

    private function notify(User $user, int $times): void
    {
        for ($i = 1; $i <= $times; $i++) {
            $user->notify(new RecordChanged("Ticket #{$i} updated", 'Somebody changed Status.'));
        }
    }

    public function test_the_bell_carries_the_number_of_unread_notifications(): void
    {
        $user = $this->signIn();
        $this->notify($user, 3);

        $this->assertSame(3, $user->unreadNotifications()->count(), 'the rows have to exist first');

        $html = $this->panelHtml();

        // The wrapper Filament renders only when the button has a badge, and the number inside it.
        $this->assertStringContainsString('fi-icon-btn-badge-ctn', $html);
        $this->assertMatchesRegularExpression('/fi-icon-btn-badge-ctn.{0,400}>\s*3\s*</s', $html);
    }

    /** Read ones stop counting, which is the half that makes the number mean anything. */
    public function test_reading_them_empties_the_count(): void
    {
        $user = $this->signIn();
        $this->notify($user, 2);

        $user->unreadNotifications->markAsRead();

        // No wrapper at all rather than a badge reading zero: `:badge="$count ?: null"` on the trigger.
        $this->assertStringNotContainsString('fi-icon-btn-badge-ctn', $this->panelHtml());
    }

    /** The bell itself is on every page of the panel, count or no count. */
    public function test_the_bell_is_rendered_in_the_topbar(): void
    {
        $user = $this->signIn();
        $this->notify($user, 1);

        $this->assertStringContainsString('fi-topbar-database-notifications-btn', $this->panelHtml());
    }

    /**
     * No broadcaster, no broadcast job.
     *
     * Every notification lists `broadcast`, and with nothing to push to each one used to become a job that
     * failed on connect — one row in `failed_jobs` per notification, forever, on any installation running
     * without Reverb. The database row (the bell) had already landed; the failure was noise. The channel is
     * withdrawn before it becomes a job, and the database channel is untouched by it.
     */
    public function test_the_broadcast_channel_is_withdrawn_when_there_is_nothing_to_push_to(): void
    {
        $user = $this->signIn();

        // phpunit.xml pins BROADCAST_CONNECTION=null — an installation without a socket.
        Event::fake([BroadcastNotificationCreated::class]);

        Notification::sendNow($user, new RecordChanged('Quiet', 'No socket here.'));

        Event::assertNotDispatched(BroadcastNotificationCreated::class);
        $this->assertSame(1, $user->unreadNotifications()->count(), 'the bell entry still lands');
    }

    /**
     * Without a socket the broadcast job is never *queued* — not queued-then-skipped.
     *
     * The listener above withdraws the channel at send time, inside a job that has already been pushed and
     * run. `via()` is where Laravel decides what to push, so filtering there is what takes the empty job
     * away too: one job per notification, the database one, and nothing for a worker to spin on.
     */
    public function test_without_a_socket_only_the_database_job_is_queued(): void
    {
        $user = $this->signIn();
        Queue::fake();

        $user->notify(new RecordChanged('Quiet', 'No socket here.'));

        Queue::assertPushed(SendQueuedNotifications::class, 1);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return (fn () => $this->channels)->call($job) === ['database'];
        });
    }

    public function test_with_a_socket_the_broadcast_job_is_queued_as_well(): void
    {
        $user = $this->signIn();
        config(['broadcasting.default' => 'reverb', 'filament.broadcasting.echo.key' => 'a-key']);
        Queue::fake();

        $user->notify(new RecordChanged('Pushed', 'Socket present.'));

        Queue::assertPushed(SendQueuedNotifications::class, 2);
    }

    /** The helper touches `broadcast` and nothing else, and keeps the order of what it leaves. */
    public function test_the_channel_filter_removes_only_broadcast(): void
    {
        $this->assertSame(['mail', 'database'], Broadcasting::channels(['mail', 'database', 'broadcast']));
        $this->assertSame(['database', 'mail'], Broadcasting::channels(['database', 'broadcast', 'mail']));
        $this->assertSame(['mail'], Broadcasting::channels(['mail']));

        config(['broadcasting.default' => 'reverb', 'filament.broadcasting.echo.key' => 'a-key']);

        $this->assertSame(['mail', 'database', 'broadcast'], Broadcasting::channels(['mail', 'database', 'broadcast']));
    }

    public function test_the_broadcast_channel_is_kept_when_a_socket_is_configured(): void
    {
        $user = $this->signIn();

        config(['broadcasting.default' => 'reverb', 'filament.broadcasting.echo.key' => 'a-key']);
        Event::fake([BroadcastNotificationCreated::class]);

        Notification::sendNow($user, new RecordChanged('Pushed', 'Socket present.'));

        Event::assertDispatched(BroadcastNotificationCreated::class);
    }

    /**
     * With no socket configured, the count cannot wait five minutes for a poll.
     *
     * The interval is the fallback for a dropped Reverb connection. Where no broadcaster exists at all it is
     * not a fallback but the only path, and five minutes of silence after something happens is the exact
     * report this answers: "the bell does not work".
     */
    public function test_the_poll_is_quick_when_nothing_can_push_to_it(): void
    {
        $interval = new ReflectionMethod(AdminPanelProvider::class, 'notificationsPollingInterval');
        $interval->setAccessible(true);

        // phpunit.xml pins BROADCAST_CONNECTION=null, which is also an installation without Reverb.
        $this->assertSame('30s', $interval->invoke(null));

        config([
            'broadcasting.default' => 'reverb',
            'filament.broadcasting.echo.key' => 'a-key',
        ]);

        $this->assertSame('300s', $interval->invoke(null), 'a socket makes polling the fallback again');
    }
}
