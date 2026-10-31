<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The bell over the API — docs/desktop-mobile-plan.md A4. The same rows the panel shows, the caller's
 * own only, flattened to what a phone draws; read marks are per notification and for all; /v1 only.
 */
class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::create(['name' => 'Bell', 'email' => $email, 'password' => bcrypt('password'), 'status' => 1]);
    }

    public function test_the_caller_reads_and_marks_their_own_notifications(): void
    {
        $user = $this->user('bell@test.local');
        $user->notifyNow(new BellProbe('Leave approved', 'Your 2 days are approved.', 'https://example.test/admin/acme/leave'));
        $user->notifyNow(new BellProbe('Payslip ready', 'March is published.'));

        $stranger = $this->user('stranger@test.local');
        $stranger->notifyNow(new BellProbe('Not yours', 'Somebody else\'s bell.'));

        Sanctum::actingAs($user, ['api']);

        $response = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('unread_count', 2);

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Leave approved', 'Payslip ready'], $titles);

        $approved = collect($response->json('data'))->firstWhere('title', 'Leave approved');
        $this->assertSame('Your 2 days are approved.', $approved['body']);
        $this->assertSame('https://example.test/admin/acme/leave', $approved['url']);
        $this->assertNull($approved['read_at']);

        $this->postJson('/api/v1/notifications/'.$approved['id'].'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $approved['id']);
        $after = collect($this->getJson('/api/v1/notifications')->json('data'))->firstWhere('id', $approved['id']);
        $this->assertNotNull($after['read_at']);

        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonPath('count', 1);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('marked', 1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_somebody_elses_notification_is_not_found(): void
    {
        $owner = $this->user('owner-bell@test.local');
        $owner->notifyNow(new BellProbe('Private', 'Theirs.'));
        $id = $owner->notifications()->first()->getKey();

        Sanctum::actingAs($this->user('other-bell@test.local'), ['api']);

        $this->postJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
        $this->assertNull($owner->notifications()->first()->read_at);
    }

    public function test_the_bell_is_on_the_versioned_surface_only(): void
    {
        Sanctum::actingAs($this->user('v1-bell@test.local'), ['api']);

        $this->getJson('/api/notifications')->assertNotFound();
        $this->getJson('/api/v1/notifications')->assertOk();
    }
}

/** A bell entry shaped the way every notification here shapes one. */
class BellProbe extends Notification
{
    public function __construct(private string $title, private string $body, private ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()->title($this->title)->body($this->body);

        if ($this->url) {
            $notification->actions([Action::make('open')->url($this->url)]);
        }

        return $notification->getDatabaseMessage();
    }
}
