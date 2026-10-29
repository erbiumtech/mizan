<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Services\DailyReports;
use App\Modules\Performance\Services\TaskRoller;
use App\Support\Ai\FakeStructuredModel;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Daily reports and the verdict on them — docs/saas-launch-plan.md §8.3. On the
 * fake model: a verdict is written to its four columns and nowhere else; the
 * model off writes nothing; the ledger changes only on the manager's accept or
 * override; and filing completes the day's daily-report task.
 */
class DailyReportVerdictTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $manager;

    private User $login;

    private Employee $employee;

    private FakeStructuredModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-09 18:00:00');
        Notification::fake();

        $this->manager = User::factory()->create(['status' => 1]);
        $this->actingAs($this->manager);
        $this->setCurrentTenant();

        foreach (['performance', 'employees', 'attendance'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->login = User::factory()->create(['status' => 1]);
        $this->employee = Employee::create(['user_id' => $this->login->getKey(), 'employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => true, 'designation' => 'Developer', 'date_of_joining' => '2020-01-01']);

        config(['ai.enabled' => true, 'ai.driver' => 'local']);
        $this->model = new FakeStructuredModel;
        $this->app->instance(StructuredModel::class, $this->model);
    }

    private function reports(): DailyReports
    {
        return app(DailyReports::class);
    }

    public function test_a_verdict_lands_in_its_four_columns_and_nowhere_else(): void
    {
        $this->model->queue(['verdict' => 'substantive', 'summary' => 'Shipped the payslip fix and started the export.', 'questions' => ['Was the export reviewed?']]);

        $report = $this->reports()->submit($this->employee, '2026-11-09', "Fixed the payslip rounding bug.\nStarted the tenant export.", $this->login);

        $this->assertSame(DailyReport::VERDICT_SUBSTANTIVE, $report->verdict);
        $this->assertSame('Shipped the payslip fix and started the export.', $report->verdict_summary);
        $this->assertSame(['Was the export reviewed?'], $report->verdict_questions);
        $this->assertNotNull($report->verdict_at);
        $this->assertSame('local', $report->verdict_model);

        // The model saw the role, today's words as the only volatile part, and no ledger write.
        $this->assertCount(1, $this->model->calls);
        $this->assertStringContainsString('Developer', $this->model->calls[0]['system']);
        $this->assertStringContainsString('payslip rounding', $this->model->calls[0]['utterance']);
        $this->assertSame(0, MeritEntry::count(), 'The model wrote the ledger.');
        $this->assertNull($report->manager_decision);

        // Yesterday travels in the system half for continuity.
        $this->model->queue(['verdict' => 'thin', 'summary' => 'Same as yesterday.', 'questions' => []]);
        $this->reports()->submit($this->employee, '2026-11-10', 'Worked on the export.', $this->login);
        $this->assertStringContainsString('Fixed the payslip rounding bug.', $this->model->calls[1]['system']);
    }

    public function test_the_model_off_or_unreachable_writes_nothing_and_the_report_still_files(): void
    {
        config(['ai.enabled' => false]);
        $report = $this->reports()->submit($this->employee, '2026-11-09', 'Did things.', $this->login);
        $this->assertFalse($report->hasVerdict());
        $this->assertSame([], $this->model->calls);

        config(['ai.enabled' => true]);
        $this->model->queue(new ModelUnavailable('down'));
        $report = $this->reports()->submit($this->employee, '2026-11-10', 'Did more things.', $this->login);
        $this->assertFalse($report->hasVerdict());
        $this->assertNotNull($report->submitted_at);
    }

    public function test_the_ledger_changes_only_on_the_managers_accept_or_override(): void
    {
        $this->model->queue(['verdict' => 'substantive', 'summary' => 'Good.', 'questions' => []]);
        $report = $this->reports()->submit($this->employee, '2026-11-09', 'Real work.', $this->login);
        $this->assertSame(0, MeritEntry::count());

        $decided = $this->reports()->decide($report, $this->manager, DailyReport::DECISION_ACCEPTED, null, 'Nice');
        $this->assertSame(DailyReport::DECISION_ACCEPTED, $decided->manager_decision);
        $this->assertSame(1, MeritEntry::count());
        $this->assertSame(2, $decided->meritEntry->points, 'Accepting a substantive verdict is +2 as configured.');
        $this->assertSame(MeritEntry::SOURCE_REPORT, $decided->meritEntry->source_type);
        $this->assertSame($this->manager->getKey(), (int) $decided->meritEntry->recorded_by);

        // Decided is final: no re-filing, no second decision.
        try {
            $this->reports()->submit($this->employee, '2026-11-09', 'Changed my mind.', $this->login);
            $this->fail('A decided report was re-filed.');
        } catch (InvalidArgumentException) {
        }

        try {
            $this->reports()->decide($decided, $this->manager, DailyReport::DECISION_OVERRIDDEN, 5);
            $this->fail('A report was decided twice.');
        } catch (InvalidArgumentException) {
        }

        // Override: the manager's own points, and zero touches nothing.
        $this->model->queue(['verdict' => 'off_topic', 'summary' => 'Not about work.', 'questions' => []]);
        $second = $this->reports()->submit($this->employee, '2026-11-10', 'Went to a wedding.', $this->login);
        $this->reports()->decide($second, $this->manager, DailyReport::DECISION_OVERRIDDEN, 0, 'Was on approved leave');
        $this->assertSame(1, MeritEntry::count());
        $this->assertNull($second->fresh()->merit_entry_id);

        $this->model->queue(['verdict' => 'thin', 'summary' => 'Vague.', 'questions' => []]);
        $third = $this->reports()->submit($this->employee, '2026-11-11', 'Stuff.', $this->login);
        $this->reports()->decide($third, $this->manager, DailyReport::DECISION_OVERRIDDEN, -1, 'Too vague');
        $this->assertSame(-1, $third->fresh()->meritEntry->points);

        // A report with no verdict cannot be "accepted"; it can be decided.
        config(['ai.enabled' => false]);
        $fourth = $this->reports()->submit($this->employee, '2026-11-12', 'No model today.', $this->login);
        $this->expectException(InvalidArgumentException::class);
        $this->reports()->decide($fourth, $this->manager, DailyReport::DECISION_ACCEPTED);
    }

    public function test_filing_completes_the_days_daily_report_task_and_re_filing_replaces(): void
    {
        config(['ai.enabled' => false]);
        RecurringTask::create(['title' => 'Daily report', 'kind' => RecurringTask::KIND_DAILY_REPORT, 'due_time' => '23:00:00', 'penalty_points' => 2]);
        app(TaskRoller::class)->roll(Carbon::parse('2026-11-09'));

        $occurrence = TaskOccurrence::where('employee_id', $this->employee->getKey())->firstOrFail();
        $this->assertTrue($occurrence->isOpen());

        $this->reports()->submit($this->employee, '2026-11-09', 'First version.', $this->login);
        $this->assertSame(TaskOccurrence::STATUS_COMPLETED, $occurrence->fresh()->status());

        $this->reports()->submit($this->employee, '2026-11-09', 'Second version.', $this->login);
        $this->assertSame(1, DailyReport::count());
        $this->assertSame('Second version.', DailyReport::first()->body);

        $this->expectException(InvalidArgumentException::class);
        $this->reports()->submit($this->employee, '2026-11-10', '   ', $this->login);
    }

    public function test_the_api_files_the_callers_own_report_and_hides_the_verdict_until_decided(): void
    {
        $this->model->queue(['verdict' => 'substantive', 'summary' => 'Good.', 'questions' => []]);
        $this->actingAs($this->login);

        $this->postJson('/api/v1/my-daily-reports', ['body' => 'Built the thing.'])
            ->assertCreated()
            ->assertJsonPath('data.report_date', '2026-11-09')
            ->assertJsonPath('data.status', 'filed')
            ->assertJsonMissingPath('data.verdict');

        $report = DailyReport::first();
        $this->assertSame(DailyReport::VERDICT_SUBSTANTIVE, $report->verdict, 'The verdict is on the row, just not in the employee\'s payload.');

        $this->reports()->decide($report, $this->manager, DailyReport::DECISION_ACCEPTED);

        $this->getJson('/api/v1/my-daily-reports')
            ->assertOk()
            ->assertJsonPath('data.0.status', DailyReport::DECISION_ACCEPTED)
            ->assertJsonPath('data.0.points', 2);

        $this->postJson('/api/v1/my-daily-reports', ['body' => ''])->assertStatus(422);
    }
}
