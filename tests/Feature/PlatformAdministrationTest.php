<?php

namespace Tests\Feature;

use App\Events\UserCreated;
use App\Modules\Core\Jobs\DeliverWebhook;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WebhookDelivery;
use App\Modules\Core\Models\WebhookEndpoint;
use App\Support\ApiAbilities;
use App\Support\SystemRoles;
use App\Support\Webhooks;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The platform screens — docs/saas-launch-plan.md §14.9: API keys, outgoing
 * webhooks, and the administration a company does of its own users and roles.
 */
class PlatformAdministrationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Administrator', 'platform@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();
    }

    // -------------------------------------------------------------- API keys

    public function test_a_key_always_carries_the_ability_the_api_requires(): void
    {
        // `RequireTokenAbility` rejects a request whose token lacks `api`, so a key
        // without it would be a key that cannot be used for anything.
        $this->assertSame(['api'], ApiAbilities::sanitise([]));
        $this->assertSame(['api'], ApiAbilities::sanitise(['payroll:write']), 'an ability nobody registered is dropped');
        $this->assertSame(['api'], ApiAbilities::sanitise(['api']));
    }

    public function test_a_key_is_issued_against_the_person_and_shown_once(): void
    {
        $token = $this->admin->createToken('Attendance sync', ApiAbilities::sanitise(['api']), now()->addDays(90));

        $this->assertStringContainsString('|', $token->plainTextToken, 'the plain text is id|secret');
        $this->assertSame(['api'], $token->accessToken->abilities);
        $this->assertSame($this->admin->getKey(), $token->accessToken->tokenable_id);

        $this->assertNotSame(
            $token->plainTextToken,
            $token->accessToken->token,
            'the table holds a hash, which is why the key cannot be shown again',
        );
    }

    // -------------------------------------------------------------- webhooks

    private function endpoint(array $events = ['user.created']): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'name' => 'Our HR system',
            'url' => 'https://hr.example.test/hooks/mizan',
            'events' => $events,
        ]);
    }

    public function test_an_endpoint_generates_its_own_secret_and_keeps_only_events_that_exist(): void
    {
        $endpoint = $this->endpoint(['user.created', 'nothing.happens']);

        $this->assertSame(64, strlen((string) $endpoint->secret), 'chosen here, never typed');
        $this->assertSame(['user.created'], $endpoint->events, 'a subscription to nothing would never deliver');
        $this->assertArrayNotHasKey('secret', $endpoint->toArray(), 'never on a screen after the first');
    }

    public function test_an_event_reaches_the_endpoints_that_asked_for_it_and_no_others(): void
    {
        Queue::fake();

        $wants = $this->endpoint(['user.created']);
        $doesNot = $this->endpoint(['employee.joined']);
        $off = tap($this->endpoint(['user.created']))->update(['is_active' => false]);

        UserCreated::dispatch($this->makeUser('Employee', 'hooked@test.local'));

        $forUserCreated = WebhookDelivery::where('event', 'user.created')->get();

        $this->assertCount(1, $forUserCreated);
        $this->assertSame($wants->getKey(), $forUserCreated->first()->webhook_endpoint_id);

        // Nothing for the endpoint that did not ask for it, and nothing for the one
        // switched off. Creating a user also creates an employee, which fires
        // `employee.joined` — so `$doesNot` has a delivery, for the event it asked for.
        $this->assertSame(0, WebhookDelivery::where('event', 'user.created')->where('webhook_endpoint_id', $doesNot->getKey())->count());
        $this->assertSame(0, WebhookDelivery::where('webhook_endpoint_id', $off->getKey())->count());

        Queue::assertPushed(DeliverWebhook::class, WebhookDelivery::count());

        $delivery = $forUserCreated->first();
        $this->assertSame('hooked@test.local', $delivery->payload['email']);
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $delivery->status, 'the row comes before the attempt');
    }

    public function test_the_payload_is_the_named_fields_and_not_the_record(): void
    {
        Queue::fake();
        $this->endpoint(['user.created']);

        UserCreated::dispatch($this->makeUser('Employee', 'fields@test.local'));

        // A payload built from the row would change the day a column is added, including
        // the day the column is something nobody meant to send.
        $this->assertSame(['id', 'name', 'email'], array_keys(WebhookDelivery::sole()->payload));
    }

    public function test_a_delivery_is_signed_with_the_endpoints_secret(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $endpoint = $this->endpoint();
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => 'user.created',
            'payload' => ['id' => 1],
        ]);

        (new DeliverWebhook($delivery->getKey()))->handle();

        Http::assertSent(function ($request) use ($endpoint): bool {
            $this->assertSame('user.created', $request->header('X-Mizan-Event')[0]);
            $this->assertSame(
                'sha256='.hash_hmac('sha256', $request->body(), $endpoint->secret),
                $request->header('X-Mizan-Signature')[0],
                'the signature is over the exact body, or a receiver cannot check it',
            );

            return true;
        });

        $this->assertTrue($delivery->refresh()->isDelivered());
        $this->assertNotNull($endpoint->refresh()->last_delivered_at);
    }

    public function test_a_refusal_is_recorded_and_thrown_so_the_queue_retries(): void
    {
        Http::fake(['*' => Http::response('no thanks', 503)]);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $this->endpoint()->getKey(),
            'event' => 'user.created',
            'payload' => ['id' => 1],
        ]);

        try {
            (new DeliverWebhook($delivery->getKey()))->handle();
            $this->fail('a refusal must throw, or the queue will not retry it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(503, $delivery->response_code);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('no thanks', (string) $delivery->error);
        $this->assertFalse($delivery->isDelivered());
    }

    public function test_an_endpoint_that_keeps_failing_is_switched_off(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->update(['consecutive_failures' => WebhookEndpoint::FAILURE_LIMIT - 1]);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => 'user.created',
            'payload' => ['id' => 1],
        ]);

        (new DeliverWebhook($delivery->getKey()))->failed(new \RuntimeException('gone'));

        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->refresh()->status);
        $this->assertFalse($endpoint->refresh()->is_active, 'ten events in a row is gone, not unlucky');
    }

    public function test_a_webhook_configuration_never_breaks_the_thing_that_fired_the_event(): void
    {
        Queue::fake();

        // An endpoint whose payload builder would throw: the registry catches it, and the
        // hire or the payslip that fired the event is unaffected.
        //
        // Overriding the registered key is safe here because the registry is repopulated
        // from the providers on every boot, and each test boots the application again.
        Webhooks::register('user.created', 'Broken', UserCreated::class, fn (): array => throw new \RuntimeException('bad builder'));
        $this->endpoint(['user.created']);

        UserCreated::dispatch($this->makeUser('Employee', 'resilient@test.local'));

        $this->assertSame(0, WebhookDelivery::count(), 'nothing is queued');
        $this->assertTrue(User::where('email', 'resilient@test.local')->exists(), 'and the user was still created');
    }

    // --------------------------------------------------- users and roles

    public function test_signing_in_stamps_the_last_seen_column(): void
    {
        $this->assertNull($this->admin->refresh()->last_login_at);

        event(new Login('web', $this->admin, false));

        $this->assertNotNull($this->admin->refresh()->last_login_at);
    }

    public function test_a_sign_in_is_attributed_to_the_one_company_somebody_belongs_to(): void
    {
        event(new Login('web', $this->admin, false));

        $row = \Spatie\Activitylog\Models\Activity::where('log_name', 'Auth')->where('event', 'login')->sole();

        $this->assertSame($this->tenant->getKey(), $row->company_id, 'so a tenant sees its own sign-ins');
    }

    public function test_the_five_seeded_roles_are_the_system_roles(): void
    {
        foreach (['Administrator', 'CEO', 'Manager', 'Accountant', 'Employee'] as $name) {
            $this->assertTrue(SystemRoles::isSystem($name));
        }

        $this->assertFalse(SystemRoles::isSystem('Site Supervisor'));
        $this->assertFalse(SystemRoles::isSystem(null));
    }

    public function test_a_copy_of_a_role_gets_a_name_nobody_has(): void
    {
        $taken = ['Manager (copy)' => true, 'Manager (copy 2)' => true];

        $this->assertSame('Manager (copy 3)', SystemRoles::copyNameFor('Manager', fn (string $n): bool => $taken[$n] ?? false));
        $this->assertSame('Manager (copy)', SystemRoles::copyNameFor('Manager', fn (): bool => false));
    }
}
