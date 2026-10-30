<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Integrations\Facts\BranchFact;
use App\Modules\Work\Integrations\Facts\CommitFact;
use App\Modules\Work\Integrations\Facts\PullRequestFact;
use App\Modules\Work\Jobs\ProcessIntegrationEvent;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemBranch;
use App\Modules\Work\Models\WorkItemCommit;
use App\Modules\Work\Models\WorkItemPullRequest;
use App\Modules\Work\Models\WorkRepository;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\CodeHosts;
use App\Modules\Work\Services\FactHandler;
use App\Modules\Work\Services\WorkItems;
use App\Support\Integrations;
use Database\Seeders\WorkWorkflowSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The repositories that talk to the board — docs/project-management-plan.md §8, for GitHub.
 *
 * In the order §15 names them: a forged signature is a 401 that queues nothing; a redelivered
 * event is processed once; a smart commit from an unmatched identity links and does not move; and
 * then the three facts each doing what §8.4 says, through `moveTo()` and onto the record.
 */
class WorkIntegrationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $company;

    private Project $project;

    private IntegrationConnection $connection;

    private WorkRepository $repository;

    private User $ahmed;

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as the status page and lead capture tests do.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
            \App\Multitenancy\Tasks\SwitchTenantFilesystemTask::class,
        ]]);

        $this->actingAs($this->makeUser('Administrator', 'hooks@test.local'));
        $this->company = $this->setCurrentTenant();

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01',
            'name' => 'The website',
            'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);

        $this->connection = IntegrationConnection::create([
            'provider' => 'github',
            'label' => 'GitHub — test',
            'auth_kind' => IntegrationConnection::AUTH_TOKEN,
            'credentials' => ['token' => 'ghp_test'],
        ]);

        $this->repository = WorkRepository::create([
            'integration_connection_id' => $this->connection->getKey(),
            'project_id' => $this->project->getKey(),
            'external_id' => '4242',
            'full_name' => 'erbiumtech/website',
            'default_branch' => 'main',
            'url' => 'https://github.com/erbiumtech/website',
            'webhook_id' => '1',
            'linked_at' => now(),
        ]);

        $this->ahmed = $this->makeUser('Employee', 'ahmed@test.local');
        $this->ahmed->forceFill(['email_verified_at' => now()])->save();
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'Export fails on Safari', ...$attributes], auth()->user());
    }

    /** @return array{username: string, id: string, email: ?string} */
    private function ahmed(): array
    {
        return ['username' => 'ahmedk', 'id' => '77', 'email' => 'ahmed@test.local'];
    }

    private function deliver(array $payload, string $event = 'push', ?string $signature = null, ?string $deliveryId = null)
    {
        $body = (string) json_encode($payload);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, $this->connection->webhook_secret);

        Company::forgetCurrent();

        $response = $this->call('POST', "/hooks/github/{$this->company->slug}/{$this->connection->getKey()}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId ?? 'd-'.md5($body),
        ], $body);

        $this->company->makeCurrent();

        return $response;
    }

    public function test_github_is_a_registered_code_provider(): void
    {
        $this->assertArrayHasKey('github', Integrations::ofKind(Integrations::CODE));
    }

    /** A forged signature is a 401 that touches no queue — §8.3. */
    public function test_a_forged_signature_is_refused_and_queues_nothing(): void
    {
        Queue::fake();

        $this->deliver(['ref' => 'refs/heads/main', 'commits' => []], signature: 'sha256=deadbeef')->assertStatus(401);

        $this->assertSame(0, IntegrationEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_company_or_connection_is_a_404(): void
    {
        Company::forgetCurrent();

        $this->postJson("/hooks/github/nobody-here/{$this->connection->getKey()}", [])->assertStatus(404);
        $this->postJson("/hooks/github/{$this->company->slug}/999999", [])->assertStatus(404);

        $this->company->makeCurrent();
    }

    /** A good delivery is stored and queued; the same delivery again is held once — §8.3. */
    public function test_a_redelivered_event_is_stored_once_and_processed_once(): void
    {
        Queue::fake();

        $payload = ['ref' => 'refs/heads/main', 'repository' => ['id' => 4242], 'commits' => []];

        $this->deliver($payload, deliveryId: 'delivery-1')->assertStatus(202)->assertJson(['duplicate' => false]);
        $this->deliver($payload, deliveryId: 'delivery-1')->assertStatus(202)->assertJson(['duplicate' => true]);

        $this->assertSame(1, IntegrationEvent::query()->count());
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
    }

    /** A commit from a username nobody here has claimed links the commit and leaves the item alone. */
    public function test_a_smart_commit_from_an_unmatched_identity_links_and_does_not_move(): void
    {
        $item = $this->item();

        app(FactHandler::class)->handle($this->connection, [
            new CommitFact('4242', ['username' => 'stranger', 'id' => '1', 'email' => null], 'abc1234def', $item->key.' #done fixed it', now(), 'https://github.com/x'),
        ]);

        $this->assertSame(1, WorkItemCommit::query()->where('work_item_id', $item->getKey())->count(), 'linked');
        $this->assertSame('To do', $item->fresh()->status->name, 'and not moved');

        $identity = IntegrationIdentity::query()->where('provider_username', 'stranger')->firstOrFail();
        $this->assertFalse($identity->isMatched());
    }

    /** Matched by verified email on first sight, the same commit moves the item — in Ahmed's name. */
    public function test_a_smart_commit_from_a_matched_identity_moves_the_item_on_the_record(): void
    {
        $item = $this->item();

        app(FactHandler::class)->handle($this->connection, [
            new CommitFact('4242', $this->ahmed(), 'abc1234def', $item->key." #done\n\nFixes the export.", now(), null),
        ]);

        $identity = IntegrationIdentity::query()->where('provider_username', 'ahmedk')->firstOrFail();
        $this->assertSame((int) $this->ahmed->getKey(), (int) $identity->user_id);
        $this->assertSame(IntegrationIdentity::BY_EMAIL, $identity->matched_how);

        $this->assertSame('Done', $item->fresh()->status->name);
        $this->assertNotNull($item->fresh()->resolved_at);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Integration',
            'subject_id' => $item->getKey(),
            'causer_id' => $this->ahmed->getKey(),
        ]);
    }

    public function test_a_smart_commit_can_comment(): void
    {
        $item = $this->item();

        app(FactHandler::class)->handle($this->connection, [
            new CommitFact('4242', $this->ahmed(), 'abc1234def', $item->key.' #comment the off-by-one was in the pager', now(), null),
        ]);

        $this->assertSame('the off-by-one was in the pager', $item->comments()->first()?->body);
    }

    /** A branch for an item in to-do starts it; deleting the branch marks the row rather than removing it. */
    public function test_a_branch_starts_an_item_and_a_deletion_is_marked_not_removed(): void
    {
        $item = $this->item();
        $name = $item->key.'-export-fix';

        app(FactHandler::class)->handle($this->connection, [new BranchFact('4242', $this->ahmed(), $name, deleted: false, url: null)]);

        $this->assertSame('In progress', $item->fresh()->status->name);
        $this->assertNotNull($item->fresh()->started_at);

        app(FactHandler::class)->handle($this->connection, [new BranchFact('4242', $this->ahmed(), $name, deleted: true, url: null)]);

        $branch = WorkItemBranch::query()->where('name', $name)->firstOrFail();
        $this->assertTrue($branch->isDeleted(), 'marked');
        $this->assertSame('In progress', $item->fresh()->status->name, 'and the item stays where it was');
    }

    /** Opening a PR moves to In review; merging moves to done when the setting says so. */
    public function test_a_pull_request_moves_to_review_then_done_on_merge(): void
    {
        $item = $this->item();

        app(FactHandler::class)->handle($this->connection, [
            new PullRequestFact('4242', $this->ahmed(), 7, 'Fix '.$item->key, $item->key.'-fix', PullRequestFact::OPENED, 'https://github.com/erbiumtech/website/pull/7', null),
        ]);

        $this->assertSame('In review', $item->fresh()->status->name);
        $pr = WorkItemPullRequest::query()->where('number', 7)->firstOrFail();
        $this->assertTrue($pr->isOpen());

        app(FactHandler::class)->handle($this->connection, [
            new PullRequestFact('4242', $this->ahmed(), 7, 'Fix '.$item->key, $item->key.'-fix', PullRequestFact::MERGED, 'https://github.com/erbiumtech/website/pull/7', now()),
        ]);

        $this->assertSame('Done', $item->fresh()->status->name);
        $this->assertSame(WorkItemPullRequest::MERGED, $pr->fresh()->state);
    }

    /** The project's own say wins over the company default — §8.4. */
    public function test_a_project_may_keep_a_merge_from_resolving(): void
    {
        $this->project->update(['work_merge_moves_to_done' => false]);
        $item = $this->item();

        app(FactHandler::class)->handle($this->connection, [
            new PullRequestFact('4242', $this->ahmed(), 8, 'Fix '.$item->key, 'fix', PullRequestFact::MERGED, null, now()),
        ]);

        $this->assertSame('To do', $item->fresh()->status->name, 'only recorded');
        $this->assertSame(WorkItemPullRequest::MERGED, WorkItemPullRequest::query()->where('number', 8)->value('state'));
    }

    /** The whole pipeline, from GitHub's own push shape to a linked commit. */
    public function test_a_push_delivery_is_parsed_into_commits(): void
    {
        $item = $this->item();

        $this->deliver([
            'ref' => 'refs/heads/main',
            'repository' => ['id' => 4242, 'html_url' => 'https://github.com/erbiumtech/website'],
            'sender' => ['login' => 'ahmedk', 'id' => 77],
            'commits' => [[
                'id' => 'f00ba4f00ba4',
                'message' => $item->key.' tidy the pager',
                'timestamp' => now()->toIso8601String(),
                'url' => 'https://github.com/erbiumtech/website/commit/f00ba4',
                'author' => ['username' => 'ahmedk', 'email' => 'ahmed@test.local'],
            ]],
        ])->assertStatus(202);

        $event = IntegrationEvent::query()->firstOrFail();
        $this->assertNotNull($event->processed_at, 'the queue is sync in tests, so it has already run');

        $commit = WorkItemCommit::query()->where('sha', 'f00ba4f00ba4')->firstOrFail();
        $this->assertSame((int) $item->getKey(), (int) $commit->work_item_id);
        $this->assertSame($item->key.' tidy the pager', $commit->message);
    }

    /** Outbound: a branch is created through the API, named for the item, and starts it. */
    public function test_creating_a_branch_calls_github_and_starts_the_item(): void
    {
        Http::fake([
            'api.github.com/repos/erbiumtech/website/git/ref/heads/main' => Http::response(['object' => ['sha' => 'abc']]),
            'api.github.com/repos/erbiumtech/website/git/refs' => Http::response(['ref' => 'refs/heads/x'], 201),
        ]);

        $item = $this->item();

        $branch = app(CodeHosts::class)->createBranch($item, $this->repository, auth()->user());

        $this->assertSame($item->key.'-export-fails-on-safari', $branch->name);
        $this->assertSame('In progress', $item->fresh()->status->name);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/git/refs')
            && $request['ref'] === 'refs/heads/'.$item->key.'-export-fails-on-safari');
    }

    /** Five failures in a row pause the connection for ten minutes, and the sixth call is refused locally. */
    public function test_the_circuit_breaker_pauses_a_failing_connection(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $item = $this->item();

        foreach (range(1, 5) as $i) {
            try {
                app(CodeHosts::class)->createBranch($item, $this->repository->fresh(), auth()->user());
            } catch (\RuntimeException) {
            }
        }

        $connection = $this->connection->fresh();
        $this->assertSame(IntegrationConnection::PAUSED, $connection->status);
        $this->assertFalse($connection->isOpen());
        $this->assertSame(5, $connection->consecutive_failures);

        Http::fake();

        try {
            app(CodeHosts::class)->createBranch($item, $this->repository->fresh(), auth()->user());
            $this->fail('a paused connection should refuse without calling out');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('paused', $e->getMessage());
        }

        Http::assertNothingSent();

        // And a paused connection accepts no deliveries either.
        $this->deliver(['ref' => 'refs/heads/main', 'repository' => ['id' => 4242], 'commits' => []])->assertStatus(503);
    }

    public function test_linking_a_pasted_pull_request_url(): void
    {
        $item = $this->item();

        $pr = app(CodeHosts::class)->linkPullRequest($item, 'https://github.com/erbiumtech/website/pull/42');

        $this->assertSame(42, $pr->number);
        $this->assertSame((int) $this->repository->getKey(), (int) $pr->work_repository_id);

        $this->expectException(\RuntimeException::class);
        app(CodeHosts::class)->linkPullRequest($item, 'https://github.com/somebody/else/pull/1');
    }
}
