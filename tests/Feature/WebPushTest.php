<?php

namespace Tests\Feature;

use App\Modules\Core\Models\DeviceToken;
use App\Modules\Core\Models\User;
use App\Support\Broadcasting;
use App\Support\Contracts\WebPushTransport;
use App\Support\WebPushTransports\ArrayTransport;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The push seam — docs/desktop-mobile-plan.md A3.
 *
 * What it promises: a client registers the subscription its browser produced and can list and revoke
 * its own devices and nobody else's; a notification on the Broadcasting seam reaches every subscribed
 * device with the bell's own title and body, without the notification class being edited; and a
 * subscription the push service reports expired is deleted rather than failed forever.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.public_key', 'BPublicKeyForTests');
        config()->set('webpush.private_key', 'PrivateKeyForTests');

        $this->transport = new ArrayTransport;
        $this->app->instance(WebPushTransport::class, $this->transport);
    }

    private function subscription(string $host = 'push.example.test'): array
    {
        return [
            'endpoint' => 'https://'.$host.'/send/'.Str::random(40),
            'keys' => ['p256dh' => 'p256dh-'.Str::random(32), 'auth' => 'auth-'.Str::random(16)],
        ];
    }

    private function user(string $email): User
    {
        return User::create(['name' => 'Push', 'email' => $email, 'password' => bcrypt('password'), 'status' => 1]);
    }

    public function test_a_client_registers_lists_and_revokes_its_own_devices(): void
    {
        $user = $this->user('push@test.local');
        Sanctum::actingAs($user, ['api']);

        $subscription = $this->subscription();

        $this->postJson('/api/v1/devices', $subscription)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('device.transport', 'webpush')
            ->assertJsonPath('device.endpoint_host', 'push.example.test')
            ->assertJsonMissingPath('device.p256dh');

        // The same browser subscribing again replaces its row rather than adding a device.
        $this->postJson('/api/v1/devices', $subscription)->assertOk();
        $this->assertSame(1, $user->deviceTokens()->count());

        $id = $this->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonCount(1, 'devices')
            ->json('devices.0.id');

        $this->deleteJson('/api/v1/devices/'.$id)->assertOk();
        $this->assertSame(0, $user->deviceTokens()->count());
    }

    public function test_a_device_cannot_be_revoked_by_somebody_else(): void
    {
        $owner = $this->user('owner@test.local');
        $token = DeviceToken::register($owner, 'https://push.example.test/send/owner', ['p256dh' => 'k', 'auth' => 'a']);

        Sanctum::actingAs($this->user('other@test.local'), ['api']);

        $this->deleteJson('/api/v1/devices/'.$token->getKey())->assertNotFound();
        $this->assertDatabaseHas('device_tokens', ['id' => $token->getKey()]);
    }

    public function test_a_subscription_needs_its_keys(): void
    {
        Sanctum::actingAs($this->user('keys@test.local'), ['api']);

        $this->postJson('/api/v1/devices', ['endpoint' => 'https://push.example.test/send/x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys']);
    }

    public function test_the_channel_is_appended_only_when_keys_are_configured(): void
    {
        $this->assertContains('webpush', Broadcasting::channels(['database', 'broadcast']));
        $this->assertNotContains('webpush', Broadcasting::channels(['database', 'mail']));

        config()->set('webpush.private_key', null);

        $this->assertNotContains('webpush', Broadcasting::channels(['database', 'broadcast']));
    }

    public function test_a_notification_on_the_seam_reaches_every_subscribed_device_with_the_bells_wording(): void
    {
        $user = $this->user('reach@test.local');
        DeviceToken::register($user, 'https://push.example.test/send/phone', ['p256dh' => 'k', 'auth' => 'a']);
        DeviceToken::register($user, 'https://push.example.test/send/laptop', ['p256dh' => 'k', 'auth' => 'a']);

        $user->notifyNow(new PushSeamProbe);

        $this->assertCount(2, $this->transport->sent);

        $payload = $this->transport->sent[0]['payload'];
        $this->assertSame('Leave approved', $payload['title']);
        $this->assertSame('Your 3 days from 2 March are approved.', $payload['body']);
        $this->assertSame('https://example.test/admin/acme/leave', $payload['url']);
        $this->assertSame($user->getKey(), $this->transport->sent[0]['user_id']);
    }

    public function test_an_expired_subscription_is_deleted_and_a_sent_one_is_touched(): void
    {
        $user = $this->user('expired@test.local');
        $dead = DeviceToken::register($user, 'https://push.example.test/send/dead', ['p256dh' => 'k', 'auth' => 'a']);
        $alive = DeviceToken::register($user, 'https://push.example.test/send/alive', ['p256dh' => 'k', 'auth' => 'a']);

        $this->transport->expired = [$dead->endpoint];

        $user->notifyNow(new PushSeamProbe);

        $this->assertDatabaseMissing('device_tokens', ['id' => $dead->getKey()]);
        $this->assertNotNull($alive->fresh()->last_used_at);
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_a_user_with_no_devices_pushes_nothing(): void
    {
        $this->user('quiet@test.local')->notifyNow(new PushSeamProbe);

        $this->assertSame([], $this->transport->sent);
    }
}

/**
 * A notification written the way every alerting notification here is: channels through the seam, a
 * Filament message for the bell, the broadcast as its mirror. Nothing in it knows about push.
 */
class PushSeamProbe extends Notification
{
    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['database', 'broadcast']);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Leave approved')
            ->body('Your 3 days from 2 March are approved.')
            ->actions([
                \Filament\Actions\Action::make('open')->url('https://example.test/admin/acme/leave'),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
