<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WebhookDelivery;
use App\Modules\Core\Models\WebhookEndpoint;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Console\Commands\SendWorkDigest;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Notifications\WorkDigest;
use App\Modules\Work\Notifications\WorkItemAssigned;
use App\Modules\Work\Notifications\WorkItemMentioned;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Support\Mentions;
use App\Support\ModuleMap;
use App\Support\TenantSettings;
use App\Support\Webhooks;
use Database\Seeders\WorkWorkflowSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Who hears what — docs/project-management-plan.md §10.
 *
 * Everything here is about *who*: the assignee hears and starts watching, a mention reaches the
 * person it names and nobody it merely resembles, the commenter joins the watchers, nobody is told
 * about their own edit, and the digest goes to the people with something in it.
 */
class WorkNotificationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private User $me;

    private User $ahmed;

    private Employee $ahmedEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->makeUser('Administrator', 'me@test.local');
        $this->actingAs($this->me);
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->ahmed = $this->makeUser('Employee', 'ahmed@test.local');
        $this->ahmedEmployee = Employee::create([
            'employee_id' => 'EMP-2',
            'name' => 'Ahmed Khan',
            'gender' => 'Male',
            'is_active' => true,
            'user_id' => $this->ahmed->getKey(),
        ]);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01',
            'name' => 'The website',
            'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    public function test_the_assignee_hears_and_starts_watching(): void
    {
        Notification::fake();

        $item = $this->item();
        $item->update(['assignee_employee_id' => $this->ahmedEmployee->getKey()]);

        Notification::assertSentTo($this->ahmed, WorkItemAssigned::class);
        $this->assertContains((int) $this->ahmed->getKey(), $item->watcherIds()->all());
    }

    /** Nobody is told about their own edit — assigning something to yourself included. */
    public function test_assigning_it_to_yourself_tells_you_nothing(): void
    {
        Notification::fake();
        $this->actingAs($this->ahmed);

        $item = $this->item(['assignee_employee_id' => $this->ahmedEmployee->getKey()]);

        Notification::assertNotSentTo($this->ahmed, WorkItemAssigned::class);
        $this->assertContains((int) $this->ahmed->getKey(), $item->watcherIds()->all(), 'but you are watching it');
    }

    /** Matched on the id, never the name: `@Ahmed` is nobody, `@user:N` and the long form are him. */
    public function test_a_mention_is_matched_on_the_id(): void
    {
        $id = $this->ahmed->getKey();

        $this->assertSame([], Mentions::in('Thanks @Ahmed, over to you')->all());
        $this->assertSame([(int) $id], Mentions::in("Thanks @user:{$id}, over to you")->all());
        $this->assertSame([(int) $id], Mentions::in('Thanks '.Mentions::token($this->ahmed).', over to you')->all());

        // A number that is not a member of this company summons nobody.
        $this->assertSame([], Mentions::in('@user:987654')->all());
    }

    public function test_a_mention_in_a_comment_notifies_the_person_and_the_commenter_watches(): void
    {
        Notification::fake();

        $item = $this->item();
        $item->comments()->create([
            'user_id' => $this->me->getKey(),
            'body' => 'Can you look, '.Mentions::token($this->ahmed).'?',
        ]);

        Notification::assertSentTo($this->ahmed, WorkItemMentioned::class, fn (WorkItemMentioned $n): bool => $n->where === 'a comment');
        Notification::assertNotSentTo($this->me, WorkItemMentioned::class);

        $watchers = $item->watcherIds()->all();
        $this->assertContains((int) $this->me->getKey(), $watchers, 'commenting adds the commenter');
        $this->assertContains((int) $this->ahmed->getKey(), $watchers, 'and the person mentioned');
    }

    public function test_a_mention_in_the_description_notifies_once(): void
    {
        Notification::fake();

        $item = $this->item(['description' => 'Pairs with '.Mentions::token($this->ahmed)]);
        $item->update(['title' => 'Retitled, description untouched']);

        Notification::assertSentToTimes($this->ahmed, WorkItemMentioned::class, 1);
    }

    public function test_every_webhook_type_the_plan_names_is_registered(): void
    {
        foreach ([
            'work_item.created', 'work_item.moved', 'work_item.assigned', 'work_item.resolved',
            'work_item.commented', 'sprint.started', 'sprint.completed', 'release.released',
        ] as $key) {
            $this->assertContains($key, Webhooks::keys());
        }
    }

    /** The payload carries the key and the status, and never the description. */
    public function test_a_resolved_item_reaches_the_endpoint_without_its_description(): void
    {
        Queue::fake();

        $endpoint = WebhookEndpoint::create([
            'name' => 'The listener',
            'url' => 'https://example.test/hook',
            'secret' => 'shh',
            'events' => ['work_item.resolved'],
            'is_active' => true,
        ]);

        $item = $this->item(['title' => 'Export fails on Safari', 'description' => 'Secret details']);
        $item->moveTo(WorkStatus::query()->where('name', 'Done')->firstOrFail(), null, 'Done');

        $delivery = WebhookDelivery::query()->where('event', 'work_item.resolved')->firstOrFail();

        $this->assertSame($endpoint->getKey(), $delivery->webhook_endpoint_id);
        $this->assertSame('WEB-1', $delivery->payload['key']);
        $this->assertSame('done', $delivery->payload['category']);
        $this->assertArrayNotHasKey('description', $delivery->payload);
    }

    /** The digest goes to the people with something in it, in the company's hour, and respects the switch. */
    public function test_the_digest_reaches_people_with_something_waiting(): void
    {
        Notification::fake();
        app(TenantSettings::class)->set('work.digest_time', now()->format('H').':00');

        $due = $this->item(['title' => 'Due today', 'assignee_employee_id' => $this->ahmedEmployee->getKey(), 'due_on' => today()->toDateString()]);
        $blocker = $this->item(['title' => 'Blocks something', 'assignee_employee_id' => $this->ahmedEmployee->getKey(), 'due_on' => today()->addWeek()->toDateString()]);
        $blocked = $this->item(['title' => 'Waiting']);
        WorkItemLink::create(['from_item_id' => $blocker->getKey(), 'to_item_id' => $blocked->getKey(), 'kind' => WorkItemLink::BLOCKS]);

        $this->artisan(SendWorkDigest::class, ['--tenant' => [$this->tenant->getKey()]])->assertSuccessful();

        Notification::assertSentTo($this->ahmed, WorkDigest::class, function (WorkDigest $digest) use ($due, $blocker): bool {
            return $digest->dueToday === [$due->display_label]
                && in_array($blocker->display_label, $digest->blockedOnYou, true)
                && count($digest->assignedSinceYesterday) === 2;
        });
        Notification::assertNotSentTo($this->me, WorkDigest::class, 'nothing is assigned to me');

        // Turned off for himself: the category switch silences it.
        Notification::fake();
        $this->ahmed->forceFill(['preferences' => ['notifications_off' => ['work']]])->save();
        $this->artisan(SendWorkDigest::class, ['--tenant' => [$this->tenant->getKey()], '--force' => true])->assertSuccessful();
        Notification::assertNotSentTo($this->ahmed, WorkDigest::class);
    }

    public function test_comments_on_a_work_item_are_stored_under_its_alias(): void
    {
        $item = $this->item();
        $comment = $item->comments()->create(['user_id' => $this->me->getKey(), 'body' => 'Noted.']);

        $this->assertSame(ModuleMap::alias(WorkItem::class), $comment->commentable_type);
    }
}
