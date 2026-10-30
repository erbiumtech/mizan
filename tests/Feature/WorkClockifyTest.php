<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Work\Integrations\Clockify\ClockifyProvider;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkTimeProject;
use App\Modules\Work\Models\WorkTimeTask;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Timers;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Services\WorkTime;
use App\Modules\Work\Support\WorkReports;
use App\Support\Integrations;
use App\Support\TenantSettings;
use Database\Seeders\WorkWorkflowSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Clockify — docs/project-management-plan.md §8.8, in the order §15 names the tests.
 *
 * The same entry delivered three times is one timesheet row; an updated entry rewrites that row
 * and a deleted one removes it; a running timer writes nothing; an entry on an unlinked project
 * is held, not dropped; in `clockify` mode Log time is absent on an item with a task and present
 * on one without; a Clockify-sourced row cannot be edited on the timesheet; and Clockify's rate is
 * never read.
 */
class WorkClockifyTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $company;

    private Project $project;

    private IntegrationConnection $connection;

    private WorkTimeProject $timeProject;

    private User $ahmed;

    private Employee $ahmedEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
            \App\Multitenancy\Tasks\SwitchTenantFilesystemTask::class,
        ]]);

        $this->actingAs($this->makeUser('Administrator', 'clock@test.local'));
        $this->company = $this->setCurrentTenant();

        foreach (['projects', 'work', 'timesheets'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->ahmed = $this->makeUser('Employee', 'ahmed@test.local');
        $this->ahmed->forceFill(['email_verified_at' => now()])->save();
        $this->ahmedEmployee = Employee::create([
            'employee_id' => 'EMP-2', 'name' => 'Ahmed Khan', 'gender' => 'Male', 'is_active' => true,
            'user_id' => $this->ahmed->getKey(), 'hourly_rate' => 8000,
        ]);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01', 'name' => 'The website', 'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);

        $this->connection = IntegrationConnection::create([
            'provider' => 'clockify',
            'label' => 'Clockify — test',
            'auth_kind' => IntegrationConnection::AUTH_TOKEN,
            'credentials' => ['api_key' => 'ck_test', 'workspace_id' => 'ws1'],
        ]);

        $this->timeProject = WorkTimeProject::create([
            'integration_connection_id' => $this->connection->getKey(),
            'project_id' => $this->project->getKey(),
            'external_project_id' => 'cp1',
            'name' => 'The website',
        ]);

        // The workspace directory, so a user id resolves to an email without a live call.
        Http::fake(['api.clockify.me/api/v1/workspaces/ws1/users*' => Http::response([
            ['id' => 'cu-ahmed', 'email' => 'ahmed@test.local', 'name' => 'Ahmed Khan'],
        ])]);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'Export fails on Safari', ...$attributes], auth()->user());
    }

    private function task(WorkItem $item): WorkTimeTask
    {
        return WorkTimeTask::create([
            'work_item_id' => $item->getKey(),
            'work_time_project_id' => $this->timeProject->getKey(),
            'external_task_id' => 'ct-'.$item->getKey(),
            'name' => $item->display_label,
        ]);
    }

    /** @return array<string, mixed> Clockify's time entry shape, with the rate it always sends */
    private function entry(string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'userId' => 'cu-ahmed',
            'projectId' => 'cp1',
            'taskId' => null,
            'description' => 'Pager fix',
            'billable' => true,
            'hourlyRate' => ['amount' => 99999, 'currency' => 'USD'],
            'timeInterval' => ['start' => '2026-11-04T09:00:00Z', 'end' => '2026-11-04T10:30:00Z', 'duration' => 'PT1H30M'],
        ], $overrides);
    }

    private function deliver(array $payload, string $type = 'NEW_TIME_ENTRY', ?string $signature = null)
    {
        Company::forgetCurrent();

        $response = $this->call('POST', "/hooks/clockify/{$this->company->slug}/{$this->connection->getKey()}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CLOCKIFY_SIGNATURE' => $signature ?? $this->connection->webhook_secret,
            'HTTP_CLOCKIFY_WEBHOOK_EVENT_TYPE' => $type,
        ], (string) json_encode($payload));

        $this->company->makeCurrent();

        return $response;
    }

    public function test_clockify_is_a_registered_time_provider(): void
    {
        $this->assertArrayHasKey('clockify', Integrations::ofKind(Integrations::TIME));
    }

    public function test_a_wrong_signing_token_is_refused(): void
    {
        $this->deliver($this->entry('e1'), signature: 'not-it')->assertStatus(401);
        $this->assertSame(0, IntegrationEvent::query()->count());
    }

    /** The same entry delivered three times is one timesheet row. */
    public function test_the_same_entry_delivered_three_times_is_one_row(): void
    {
        $item = $this->item();
        $this->task($item);

        foreach (range(1, 3) as $i) {
            $this->deliver($this->entry('e1', ['taskId' => 'ct-'.$item->getKey()]))->assertStatus(202);
        }

        $rows = TimesheetEntry::query()->where('source', WorkTime::CLOCKIFY)->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(90, $row->minutes);
        $this->assertSame((int) $item->getKey(), (int) $row->work_item_id);
        $this->assertSame((int) $this->ahmedEmployee->getKey(), (int) $row->employee_id);
        $this->assertSame('2026-11-04', $row->date->toDateString());
        $this->assertTrue($row->is_billable);
        $this->assertSame('Pager fix', $row->description);
        $this->assertSame($this->connection->getKey().':e1', $row->external_ref);
    }

    /** An updated entry rewrites the row; a deleted one removes it. */
    public function test_an_update_rewrites_the_row_and_a_delete_removes_it(): void
    {
        $this->deliver($this->entry('e2'))->assertStatus(202);
        $this->deliver($this->entry('e2', ['timeInterval' => ['end' => '2026-11-04T11:00:00Z'], 'description' => 'Pager fix, and the tests']), 'TIME_ENTRY_UPDATED')->assertStatus(202);

        $rows = TimesheetEntry::query()->where('source', WorkTime::CLOCKIFY)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(120, $rows->first()->minutes);
        $this->assertSame('Pager fix, and the tests', $rows->first()->description);
        $this->assertNull($rows->first()->work_item_id, 'no task: time on the project with no item, still true');

        $this->deliver($this->entry('e2'), 'TIME_ENTRY_DELETED')->assertStatus(202);
        $this->assertSame(0, TimesheetEntry::query()->where('source', WorkTime::CLOCKIFY)->count());
    }

    /** A running timer writes nothing: the ledger holds hours, not intentions. */
    public function test_a_running_timer_writes_nothing(): void
    {
        $this->deliver($this->entry('e3', ['timeInterval' => ['end' => null, 'duration' => null]]))->assertStatus(202);

        $this->assertSame(0, TimesheetEntry::query()->count());
        $this->assertNotNull(IntegrationEvent::query()->first()->processed_at, 'processed, with nothing to write');
    }

    /** An entry on an unlinked project is held with the reason, not dropped. */
    public function test_an_entry_on_an_unlinked_project_is_held(): void
    {
        $this->deliver($this->entry('e4', ['projectId' => 'cp-unlinked']))->assertStatus(202);

        $event = IntegrationEvent::query()->firstOrFail();
        $this->assertNotNull($event->failed_at);
        $this->assertStringContainsString('not linked', $event->failure);
        $this->assertSame(0, TimesheetEntry::query()->count());
        $this->assertSame(1, $this->connection->fresh()->eventsWaitingOnIdentity());
    }

    /** In clockify mode, Log time is absent on an item with a task and present on one without. */
    public function test_in_clockify_mode_log_time_steps_aside_only_where_there_is_a_task(): void
    {
        app(TenantSettings::class)->set('work.time_source', Timers::CLOCKIFY);
        IntegrationIdentity::create([
            'integration_connection_id' => $this->connection->getKey(),
            'provider_username' => 'cu-ahmed', 'provider_user_id' => 'cu-ahmed', 'email' => 'ahmed@test.local',
            'user_id' => $this->ahmed->getKey(), 'matched_how' => IntegrationIdentity::BY_EMAIL,
        ]);

        $withTask = $this->item(['title' => 'Has a task']);
        $this->task($withTask);
        $without = $this->item(['title' => 'No task']);

        $timers = app(Timers::class);

        $this->assertFalse($timers->logTimeAllowed($withTask, $this->ahmed), 'one door: the timer');
        $this->assertTrue($timers->logTimeAllowed($without, $this->ahmed), 'no task yet, so Log time stays');
        $this->assertTrue($timers->logTimeAllowed($withTask, auth()->user()), 'and a user with no linked identity has no other way');
        $this->assertTrue($timers->timerAvailable($withTask, $this->ahmed));
        $this->assertFalse($timers->timerAvailable($withTask, auth()->user()));

        // In `here` mode the buttons are absent and Log time is everywhere.
        app(TenantSettings::class)->set('work.time_source', Timers::HERE);
        $this->assertFalse($timers->timerAvailable($withTask, $this->ahmed));
        $this->assertTrue($timers->logTimeAllowed($withTask, $this->ahmed));

        // A project overrides the company.
        $this->project->update(['work_time_source' => Timers::EITHER]);
        $this->assertTrue($timers->timerAvailable($withTask->fresh(), $this->ahmed));
        $this->assertTrue($timers->logTimeAllowed($withTask->fresh(), $this->ahmed));
    }

    public function test_a_clockify_row_cannot_be_edited_on_the_timesheet(): void
    {
        $this->deliver($this->entry('e5'))->assertStatus(202);
        $row = TimesheetEntry::query()->where('source', WorkTime::CLOCKIFY)->firstOrFail();

        $this->expectException(ValidationException::class);

        $row->update(['minutes' => 5]);
    }

    /** Clockify's rate is never read: cost comes from LabourCost, which here is null. */
    public function test_clockifys_rate_is_never_read(): void
    {
        $item = $this->item();
        $this->task($item);

        $this->deliver($this->entry('e6', ['taskId' => 'ct-'.$item->getKey(), 'hourlyRate' => ['amount' => 99999]]))->assertStatus(202);

        $summary = app(WorkTime::class)->summaryFor($item);
        $this->assertSame(90, $summary['logged']);
        $this->assertNull($summary['cost'], 'no payroll rate, so uncosted — never 99,999 an hour');
        $this->assertSame(1, $summary['uncosted']);

        $report = app(WorkReports::class)->timeBySource('2026-11-04');
        $this->assertSame('The website', $report['rows'][0][0]);
        $this->assertSame(['—', '1.5', '—', '—'], array_slice($report['rows'][0], 2), 'an hour and a half from the tracker, nothing from the form');
    }

    /** Starting a timer makes the task first, then starts an entry on it through the API. */
    public function test_starting_a_timer_creates_the_task_then_the_entry(): void
    {
        app(TenantSettings::class)->set('work.time_source', Timers::CLOCKIFY);
        IntegrationIdentity::create([
            'integration_connection_id' => $this->connection->getKey(),
            'provider_username' => 'cu-ahmed', 'provider_user_id' => 'cu-ahmed', 'email' => 'ahmed@test.local',
            'user_id' => $this->ahmed->getKey(), 'matched_how' => IntegrationIdentity::BY_EMAIL,
        ]);

        Http::fake([
            'api.clockify.me/api/v1/workspaces/ws1/projects/cp1/tasks' => Http::response(['id' => 'ct-new', 'name' => 'x'], 201),
            'api.clockify.me/api/v1/workspaces/ws1/user/cu-ahmed/time-entries' => Http::response(['id' => 'e-running'], 201),
        ]);

        $item = $this->item();
        app(Timers::class)->start($item, $this->ahmed);

        $task = WorkTimeTask::query()->where('work_item_id', $item->getKey())->firstOrFail();
        $this->assertSame('ct-new', $task->external_task_id);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/projects/cp1/tasks') && $request['name'] === $item->key.' · Export fails on Safari');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/user/cu-ahmed/time-entries') && $request['taskId'] === 'ct-new');
    }

    public function test_the_provider_parses_an_entry_without_reading_its_rate(): void
    {
        $fact = app(ClockifyProvider::class)->fact($this->entry('e7'));

        $this->assertSame(90, $fact->minutes());
        $this->assertFalse($fact->isRunning());
        $this->assertSame('cu-ahmed', $fact->externalUserId);
        $this->assertObjectNotHasProperty('hourlyRate', $fact);
    }
}
