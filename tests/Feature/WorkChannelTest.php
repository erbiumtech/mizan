<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkChannelSubscription;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Notifications\WorkChannelPost;
use App\Modules\Work\Services\WorkItems;
use Database\Seeders\WorkWorkflowSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Chat — docs/project-management-plan.md §8.7.
 *
 * One line per chosen event, to the channel that asked for it and no other; Teams through its
 * webhook, Slack through the notification channel that is silent when no bot token is configured.
 */
class WorkChannelTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'chat@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01', 'name' => 'The website', 'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);
    }

    public function test_a_teams_channel_hears_the_events_it_asked_for_and_no_others(): void
    {
        Http::fake(['*.webhook.office.com/*' => Http::response('1', 200)]);

        $subscription = WorkChannelSubscription::create([
            'project_id' => $this->project->getKey(),
            'kind' => WorkChannelSubscription::TEAMS,
            'destination' => 'https://acme.webhook.office.com/webhookb2/abc',
            'events' => ['item.done'],
        ]);

        $item = app(WorkItems::class)->create($this->project, ['title' => 'Export fails on Safari'], auth()->user());

        Http::assertNothingSent();

        $item->moveTo(WorkStatus::query()->where('name', 'Done')->firstOrFail(), null, 'Done');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'WEB-1 done: Export fails on Safari'));

        $this->assertNotNull($subscription->fresh()->last_posted_at);
    }

    public function test_a_failing_webhook_is_recorded_and_never_breaks_the_save(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $subscription = WorkChannelSubscription::create([
            'project_id' => $this->project->getKey(),
            'kind' => WorkChannelSubscription::TEAMS,
            'destination' => 'https://acme.webhook.office.com/webhookb2/abc',
            'events' => ['item.created'],
        ]);

        $item = app(WorkItems::class)->create($this->project, ['title' => 'Still files'], auth()->user());

        $this->assertNotNull($item->getKey(), 'the item saved regardless');
        $this->assertStringContainsString('500', $subscription->fresh()->last_failure);
    }

    /** Slack without a bot token: nothing is queued and nothing fails — the rule the rest of the app follows. */
    public function test_slack_is_silent_without_a_bot_token_and_posts_with_one(): void
    {
        Notification::fake();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);

        WorkChannelSubscription::create([
            'project_id' => $this->project->getKey(),
            'kind' => WorkChannelSubscription::SLACK,
            'destination' => '#website-dev',
            'events' => ['item.created'],
        ]);

        app(WorkItems::class)->create($this->project, ['title' => 'Quiet'], auth()->user());
        Notification::assertNothingSent();

        config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);

        app(WorkItems::class)->create($this->project, ['title' => 'Heard'], auth()->user());
        Notification::assertSentOnDemand(WorkChannelPost::class, fn (WorkChannelPost $post, array $channels, $notifiable): bool => str_contains($post->text, 'Heard')
            && ($notifiable->routes['slack'] ?? null) === '#website-dev');
    }

    /** A webhook URL on an arbitrary host is not a Teams webhook, and the server never posts to it. */
    public function test_a_destination_off_the_teams_hosts_is_refused_and_never_posted_to(): void
    {
        $this->assertTrue(WorkChannelSubscription::isAllowedTeamsDestination('https://acme.webhook.office.com/webhookb2/abc'));
        $this->assertTrue(WorkChannelSubscription::isAllowedTeamsDestination('https://prod-12.westeurope.logic.azure.com:443/workflows/x'));
        $this->assertFalse(WorkChannelSubscription::isAllowedTeamsDestination('http://acme.webhook.office.com/x'), 'https only');
        $this->assertFalse(WorkChannelSubscription::isAllowedTeamsDestination('https://127.0.0.1/admin'));
        $this->assertFalse(WorkChannelSubscription::isAllowedTeamsDestination('https://webhook.office.com.evil.example/x'));

        Http::fake();

        $subscription = WorkChannelSubscription::create([
            'project_id' => $this->project->getKey(),
            'kind' => WorkChannelSubscription::TEAMS,
            'destination' => 'https://10.0.0.5/internal',
            'events' => ['item.created'],
        ]);

        app(WorkItems::class)->create($this->project, ['title' => 'Never reaches the LAN'], auth()->user());

        Http::assertNothingSent();
        $this->assertStringContainsString('not a Teams webhook host', $subscription->fresh()->last_failure);
    }

    public function test_an_inactive_subscription_hears_nothing(): void
    {
        Http::fake();

        WorkChannelSubscription::create([
            'project_id' => $this->project->getKey(),
            'kind' => WorkChannelSubscription::TEAMS,
            'destination' => 'https://acme.webhook.office.com/webhookb2/abc',
            'events' => ['item.created'],
            'is_active' => false,
        ]);

        app(WorkItems::class)->create($this->project, ['title' => 'Unheard'], auth()->user());

        Http::assertNothingSent();
    }
}
