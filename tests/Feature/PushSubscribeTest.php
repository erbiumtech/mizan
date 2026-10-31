<?php

namespace Tests\Feature;

use App\Modules\Core\Models\DeviceToken;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel subscribing itself to push — docs/desktop-mobile-plan.md M2.
 *
 * The browser does the subscribing; the server's part is to record the subscription against the session's
 * user and forget it by endpoint when the browser lets go. Both need a session and nothing else: a person
 * at the panel has no bearer token.
 */
class PushSubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Panel', 'email' => 'panel-push@test.local', 'password' => bcrypt('password'), 'status' => 1]);
    }

    public function test_a_signed_in_person_subscribes_and_unsubscribes_this_device(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/push/subscriptions', [
                'endpoint' => 'https://push.example.test/send/panel-device',
                'keys' => ['p256dh' => 'p', 'auth' => 'a'],
            ])
            ->assertCreated()
            ->assertJsonPath('device.endpoint_host', 'push.example.test');

        $this->assertSame(1, DeviceToken::query()->where('user_id', $user->getKey())->count());

        $this->actingAs($user)
            ->deleteJson('/push/subscriptions', ['endpoint' => 'https://push.example.test/send/panel-device'])
            ->assertOk();

        $this->assertSame(0, DeviceToken::query()->where('user_id', $user->getKey())->count());
    }

    public function test_the_switch_can_ask_whether_this_browsers_subscription_is_the_callers(): void
    {
        $user = $this->user();
        $other = User::create(['name' => 'Other', 'email' => 'other-mine@test.local', 'password' => bcrypt('password'), 'status' => 1]);
        DeviceToken::register($user, 'https://push.example.test/send/mine', ['p256dh' => 'p', 'auth' => 'a']);
        DeviceToken::register($other, 'https://push.example.test/send/theirs', ['p256dh' => 'p', 'auth' => 'a']);

        $this->actingAs($user)
            ->getJson('/push/subscriptions/mine?endpoint='.urlencode('https://push.example.test/send/mine'))
            ->assertOk()
            ->assertJsonPath('mine', true);

        $this->actingAs($user)
            ->getJson('/push/subscriptions/mine?endpoint='.urlencode('https://push.example.test/send/theirs'))
            ->assertOk()
            ->assertJsonPath('mine', false);
    }

    public function test_subscribing_needs_a_session(): void
    {
        $this->postJson('/push/subscriptions', [
            'endpoint' => 'https://push.example.test/send/nobody',
            'keys' => ['p256dh' => 'p', 'auth' => 'a'],
        ])->assertUnauthorized();
    }

    public function test_unsubscribing_touches_only_the_callers_own_device(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other-push@test.local', 'password' => bcrypt('password'), 'status' => 1]);
        DeviceToken::register($other, 'https://push.example.test/send/shared', ['p256dh' => 'p', 'auth' => 'a']);

        $this->actingAs($this->user())
            ->deleteJson('/push/subscriptions', ['endpoint' => 'https://push.example.test/send/shared'])
            ->assertOk();

        $this->assertSame(1, DeviceToken::query()->where('user_id', $other->getKey())->count());
    }

    public function test_the_public_key_is_in_the_head_only_when_configured(): void
    {
        config()->set('webpush.public_key', null);
        $this->assertStringNotContainsString('webpush-public-key', view('filament.partials.pwa-head')->render());

        config()->set('webpush.public_key', 'BPublicKey');
        config()->set('webpush.private_key', 'private');
        $this->assertStringContainsString('content="BPublicKey"', view('filament.partials.pwa-head')->render());
        $this->assertStringContainsString('Notifications on this device', view('filament.partials.push-subscribe')->render());
    }
}
