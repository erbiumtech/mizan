<?php

namespace Tests\Feature;

use App\Modules\Core\Console\Commands\DeliverScheduledReports;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Filament\Resources\ReportSchedules\Pages\CreateReportSchedule;
use App\Modules\Core\Filament\Resources\ReportSchedules\Pages\ListReportSchedules;
use App\Modules\Core\Jobs\DeliverScheduledReport;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Core\Models\ReportDelivery;
use App\Modules\Core\Models\ReportSchedule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Reporting\InvoiceDataset;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Reporting\PayslipDataset;
use App\Notifications\ScheduledReportDelivered;
use App\Notifications\ScheduledReportFailed;
use App\Support\Reporting\RelativePeriod;
use App\Support\Reporting\ReportDeliveryService;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Scheduled and emailed reports — `docs/reports-expansion-plan.md` Phase 8.
 *
 * **Item 2 says "the security model, which is the whole of this phase", and this file is arranged that way.**
 * An emailed report leaves the application's authorization behind — nobody has to log in to read it — so the
 * tests that matter are the four the item names:
 *
 *  - the render runs **as the owner**, so a schedule cannot show its owner rows they could not open;
 *  - the recipient list is re-authorised **at send time**, because somebody leaving is the ordinary case;
 *  - an **external** recipient needs its own permission, and every delivery records who was external;
 *  - a schedule whose owner **loses access is suspended**, not quietly rendered with fewer rows.
 *
 * And item 4's guarantee, which is the one a queue will break for you if it is missing: **one delivery per
 * period, whatever the queue does.** A retried render must be one email.
 *
 * The reports themselves are `BuiltReportTest`'s and each coded report's own tests; the periods are
 * `RelativePeriod`'s. What is tested here is what happens between a timetable and a mailbox.
 */
class ScheduledReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** A Friday in February of the 2026-2027 financial year. */
    private const TODAY = '2027-02-19';

    /** 07:00 in Karachi, which is 02:00 UTC — the difference the timezone column exists for. */
    private const KARACHI_SEVEN = '2027-02-19 02:00:00';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as ModuleEnforcementTest and StatusPageTest do.
        // The command under test is `TenantAware`, so it makes each company current — which throws outright
        // where there is no tenant connection to switch to.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
        ]]);

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->owner = $this->makeUser('Administrator', 'owner@test.local');
        $this->actingAs($this->owner);
        $this->setCurrentTenant();

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ───────────────────────────────────────────── the timetable and the period ──

    /**
     * Due at 07:00 in the schedule's own timezone, not the server's.
     *
     * "Monthly on the 1st at 07:00" for a company in Karachi is not the same instant as it is here, and a
     * report that arrives a day early every other month is the failure this column prevents.
     */
    public function test_a_schedule_is_due_in_its_own_timezone(): void
    {
        $schedule = $this->schedule(['cron' => '0 7 * * *', 'timezone' => 'Asia/Karachi']);

        $this->assertTrue($schedule->isDueAt(Carbon::parse(self::KARACHI_SEVEN, 'UTC')));

        // 07:00 UTC is midday in Karachi, which this schedule has nothing to say about.
        $this->assertFalse($schedule->isDueAt(Carbon::parse('2027-02-19 07:00:00', 'UTC')));
    }

    /**
     * The window is the scheduler's interval, because the command runs every fifteen minutes.
     *
     * Without it a 07:00 report would only ever be sent by a run that landed exactly on 07:00 — which is the
     * kind of arithmetic that works on the developer's machine and misses every other night in production.
     */
    public function test_being_due_covers_the_scheduler_interval(): void
    {
        $schedule = $this->schedule(['cron' => '0 7 * * *', 'timezone' => 'Asia/Karachi']);

        $this->assertTrue($schedule->isDueAt(Carbon::parse('2027-02-19 02:10:00', 'UTC')), 'ten minutes late is still this run');
        $this->assertFalse($schedule->isDueAt(Carbon::parse('2027-02-19 02:20:00', 'UTC')), 'twenty minutes late belongs to no run');
    }

    /** A schedule that is switched off, or suspended, is never due. */
    public function test_an_inactive_or_suspended_schedule_is_never_due(): void
    {
        $off = $this->schedule(['is_active' => false]);
        $suspended = $this->schedule(['recipients' => ['other@test.local']]);
        $suspended->suspend('The owner no longer has permission.');

        $at = Carbon::parse(self::KARACHI_SEVEN, 'UTC');

        $this->assertFalse($off->isDueAt($at));
        $this->assertFalse($suspended->isDueAt($at));
        $this->assertTrue(app(ReportDeliveryService::class)->due($at)->isEmpty());
    }

    /**
     * The period key is the resolved span, which is what makes it an idempotency key.
     *
     * Item 3: "the resolved period is also the idempotency key in item 4 — so getting it wrong is not a
     * cosmetic error but a double send". And the year is financial, which is the reason `ReportPeriod` exists.
     */
    public function test_the_period_key_is_the_resolved_span(): void
    {
        $monthly = $this->schedule(['period' => RelativePeriod::LAST_MONTH]);

        $this->assertSame(
            'last_month:2027-01-01..2027-01-31',
            $monthly->periodKey(Carbon::parse(self::TODAY)),
        );

        // Read on any day of February, the same month — which is what stops a daily run sending it thirty
        // times.
        $this->assertSame(
            $monthly->periodKey(Carbon::parse(self::TODAY)),
            $monthly->periodKey(Carbon::parse('2027-02-27')),
        );

        // The financial year starts on 1 July here, not on 1 January.
        $yearly = $this->schedule(['period' => RelativePeriod::YEAR_TO_DATE, 'recipients' => ['a@test.local']]);

        $this->assertSame(
            'year_to_date:2026-07-01..2027-02-19',
            $yearly->periodKey(Carbon::parse(self::TODAY)),
        );
    }

    // ──────────────────────────────────────────────── one delivery per period ──

    /**
     * One delivery per period, whatever the queue does — item 4.
     *
     * The second call finds the period already answered and does nothing, which is what a retried job is: the
     * row is written before the work, so the window between the mail leaving and the record landing does not
     * exist.
     */
    public function test_one_delivery_per_period_whatever_the_queue_does(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);
        $schedule = $this->schedule();
        $reports = app(ReportDeliveryService::class);

        $first = $reports->deliver($schedule, Carbon::parse(self::TODAY));
        $second = $reports->deliver($schedule, Carbon::parse(self::TODAY));

        $this->assertNotNull($first);
        $this->assertNull($second, 'the same period was delivered twice');

        $this->assertSame(1, ReportDelivery::query()->count());
        $this->assertSame(ReportDelivery::STATUS_SENT, $first->refresh()->status);
        Notification::assertSentOnDemandTimes(ScheduledReportDelivered::class, 1);
    }

    /** A different period is a different send. */
    public function test_the_next_period_is_delivered(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);
        $schedule = $this->schedule(['period' => RelativePeriod::THIS_MONTH]);
        $reports = app(ReportDeliveryService::class);

        $reports->deliver($schedule, Carbon::parse('2027-02-19'));
        // "This month" runs to the day it is read, so tomorrow is a different span and a different report.
        $reports->deliver($schedule, Carbon::parse('2027-02-20'));

        $this->assertSame(2, ReportDelivery::query()->count());
        Notification::assertSentOnDemandTimes(ScheduledReportDelivered::class, 2);
    }

    // ────────────────────────────────────────────────────── the security model ──

    /**
     * The render runs as the owner — item 2, and the sharpest test in this file.
     *
     * The schedule belongs to an employee, and the report is a shared one over payslips. Rendered as the
     * owner, it carries their own row and nobody else's — the `EmployeeAccess` scoping Phase 6.1 put on the
     * dataset's base query. Rendered as nobody, or as everybody, it would carry both.
     */
    public function test_the_render_runs_as_the_owner(): void
    {
        $employee = $this->employeeFor('staff@test.local', 'Own record');
        $other = Employee::create([
            'employee_id' => 'E-OTHER',
            'name' => 'Somebody else',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2026-07-01',
        ]);

        $this->payslip($employee, 'January');
        $this->payslip($other, 'January');

        // Shared by an Administrator, so the employee may read it at all.
        $definition = ReportDefinition::put('Payslips', PayslipDataset::class, [
            'columns' => ['employee', 'month'],
        ], isPublic: true);

        $staff = $employee->user;

        $schedule = $this->schedule([
            'user_id' => $staff->getKey(),
            'report_key' => $definition->reportKey(),
            'recipients' => ['staff@test.local'],
        ]);

        $payload = app(ReportDeliveryService::class)->renderAs($staff, $schedule, Carbon::parse(self::TODAY));

        $this->assertNotNull($payload);
        $this->assertSame([['Own record', 'January']], $payload['rows']);

        // And the acting user is put back, so a command that delivers two schedules does not leak the first
        // owner into the second's render.
        $this->assertSame($this->owner->getKey(), auth()->id());
    }

    /**
     * A schedule whose owner loses access is suspended, not rendered with fewer rows — item 2.
     *
     * "A schedule whose owner loses access to the report is suspended, not silently rendered with fewer
     * rows." The reason is that nobody notices the second one.
     */
    public function test_a_schedule_whose_owner_lost_access_is_suspended(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);

        $leaver = $this->makeUser('Administrator', 'leaver@test.local');
        $schedule = $this->schedule(['user_id' => $leaver->getKey()]);

        $leaver->removeFromCompany($this->tenant);

        $this->assertNull(app(ReportDeliveryService::class)->deliver($schedule, Carbon::parse(self::TODAY)));

        $schedule->refresh();

        $this->assertTrue($schedule->isSuspended());
        $this->assertStringContainsString('no longer a member', mb_strtolower((string) $schedule->suspended_reason));
        // Named precisely: "no account at all" and "left this company" are two different fixes.
        $this->assertStringContainsString('owner', mb_strtolower((string) $schedule->suspended_reason));

        // Nothing rendered, nothing sent, and no delivery row claiming the period — a suspension is not a
        // delivery that failed.
        $this->assertSame(0, ReportDelivery::query()->count());
        Notification::assertNothingSent();

        // And it is not picked up again until somebody resumes it.
        $this->assertFalse($schedule->isDueAt(Carbon::parse(self::KARACHI_SEVEN, 'UTC')));
    }

    /**
     * Recipients are re-authorised at send time — item 2.
     *
     * "A person whose role changed or who left the company is the ordinary case, and the schedule would
     * otherwise keep posting to them for years." Both halves are asserted: the one who left is not sent to,
     * and the delivery records that they were not.
     */
    public function test_recipients_are_re_authorised_at_send_time(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);

        $stayed = $this->makeUser('Accountant', 'stayed@test.local');
        $left = $this->makeUser('Accountant', 'left@test.local');

        $schedule = $this->schedule(['recipients' => ['stayed@test.local', 'left@test.local']]);

        $left->removeFromCompany($this->tenant);

        $delivery = app(ReportDeliveryService::class)->deliver($schedule, Carbon::parse(self::TODAY));

        Notification::assertSentOnDemandTimes(ScheduledReportDelivered::class, 1);

        $recorded = collect($delivery->refresh()->recipients)->keyBy('email');

        $this->assertTrue($recorded['stayed@test.local']['sent']);
        $this->assertFalse($recorded['left@test.local']['sent']);
        $this->assertStringContainsString('member', mb_strtolower($recorded['left@test.local']['reason']));
    }

    /**
     * An external recipient needs its own permission, and is recorded as external — item 2.
     *
     * The *owner's* permission, because they are the one whose access produced the rows and therefore the one
     * choosing to send them outside the company.
     */
    public function test_an_external_recipient_needs_the_owners_permission(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);

        $accountant = $this->makeUser('Accountant', 'books@test.local');
        $refused = $this->schedule([
            'user_id' => $accountant->getKey(),
            'recipients' => ['auditor@outside.example'],
        ]);

        $delivery = app(ReportDeliveryService::class)->deliver($refused, Carbon::parse(self::TODAY));

        Notification::assertNothingSent();
        $this->assertSame(ReportDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertTrue($delivery->recipients[0]['external']);
        $this->assertFalse($delivery->recipients[0]['sent']);

        // An Administrator holds `ReportSendExternal`, so the same address is allowed and recorded as
        // external — which is the audit trail item 2 asks for.
        $allowed = $this->schedule([
            'recipients' => ['auditor@outside.example'],
            'period' => RelativePeriod::THIS_QUARTER,
        ]);

        $sent = app(ReportDeliveryService::class)->deliver($allowed, Carbon::parse(self::TODAY));

        Notification::assertSentOnDemandTimes(ScheduledReportDelivered::class, 1);
        $this->assertSame(ReportDelivery::STATUS_SENT, $sent->refresh()->status);
        $this->assertTrue($sent->recipients[0]['external']);
        $this->assertTrue($sent->recipients[0]['sent']);
    }

    // ───────────────────────────────────────────── what actually gets emailed ──

    /**
     * The email carries the file and a link to the live report — item 7.
     *
     * The link matters as much as the attachment: "a recipient who wants to drill in lands in the application
     * and is authorised there" — as themselves, rather than as the owner whose access rendered the file.
     */
    public function test_the_email_carries_the_report_and_a_link_to_it(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 4200);
        $schedule = $this->schedule(['format' => ReportSchedule::FORMAT_CSV]);

        app(ReportDeliveryService::class)->deliver($schedule, Carbon::parse(self::TODAY));

        Notification::assertSentOnDemand(
            ScheduledReportDelivered::class,
            function (ScheduledReportDelivered $notification, array $channels, object $notifiable): bool {
                $this->assertSame(['mail'], $channels);
                $this->assertSame('boss@test.local', $notifiable->routes['mail'] ?? null);

                $this->assertCount(1, $notification->files);
                $this->assertStringEndsWith('.csv', $notification->files[0]['name']);
                $this->assertStringContainsString('INV-1', $notification->files[0]['data']);

                // The link is the hub with this report selected — item 1's "the schedule and the link are the
                // same thing".
                $this->assertStringContainsString('selected=', (string) $notification->link);

                return true;
            },
        );
    }

    /**
     * A report too large to attach arrives as a link instead — item 7.
     *
     * "A 40 MB PDF does not fail in this application, it fails at somebody's mail server, hours later,
     * silently." Built directly rather than by generating nine megabytes of report: what is under test is the
     * cap, not the exporter.
     */
    public function test_a_report_too_large_to_attach_becomes_a_link(): void
    {
        $notification = new ScheduledReportDelivered(
            title: 'Aged Receivables',
            subtitle: 'Acme · January',
            period: 'Last month',
            files: [[
                'name' => 'aged.csv',
                'mime' => 'text/csv',
                'data' => str_repeat('x', ReportDeliveryService::MAX_ATTACHMENT_BYTES + 1),
            ]],
            link: 'https://example.test/reports',
        );

        $this->assertTrue($notification->tooLarge());

        $mail = $notification->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

        $this->assertSame([], $mail->rawAttachments, 'the attachment was sent anyway');
        $this->assertStringContainsString('too large', implode(' ', $mail->introLines));
        $this->assertSame('https://example.test/reports', $mail->actionUrl);
    }

    /** Nothing left to send to is recorded as not sent, rather than as a failure. */
    public function test_nothing_to_send_to_is_recorded_rather_than_failed(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);
        $schedule = $this->schedule(['recipients' => ['nobody@outside.example']]);
        $schedule->forceFill(['user_id' => $this->makeUser('Accountant', 'plain@test.local')->getKey()])->save();

        $delivery = app(ReportDeliveryService::class)->deliver($schedule, Carbon::parse(self::TODAY));

        Notification::assertNothingSent();
        $this->assertSame(ReportDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertStringContainsString('authorised', (string) $delivery->error);
    }

    // ──────────────────────────────────────────── the command and the failure ──

    /** The command dispatches only what is due, one job each — item 5. */
    public function test_the_command_dispatches_only_what_is_due(): void
    {
        Bus::fake();

        $due = $this->schedule(['cron' => '0 7 * * *', 'timezone' => 'Asia/Karachi']);
        $this->schedule([
            'cron' => '0 7 1 * *',
            'timezone' => 'Asia/Karachi',
            'recipients' => ['monthly@test.local'],
        ]);

        Carbon::setTestNow(self::KARACHI_SEVEN);

        // `makeCurrent()` and an explicit `--tenant`, as every other TenantAware command's test does: the
        // command's own loop over every company is the tenancy package's, and the suite runs single-database.
        $this->tenant->makeCurrent();

        try {
            $this->artisan(DeliverScheduledReports::class, ['--tenant' => [$this->tenant->getKey()]])
                ->assertSuccessful();
        } finally {
            \App\Modules\Core\Models\Company::forgetCurrent();
        }

        Bus::assertDispatchedTimes(DeliverScheduledReport::class, 1);
        Bus::assertDispatched(
            DeliverScheduledReport::class,
            fn (DeliverScheduledReport $job): bool => $job->scheduleId === $due->getKey(),
        );
    }

    /**
     * The command is invokable by hand and on the timetable — item 5's "one line".
     *
     * Both halves, because they are wired separately: `Schedule::command()` sets the timetable and
     * `$this->commands()` is what makes it runnable at all. A command nobody can invoke by hand is a command
     * nobody can re-run after a failed night.
     */
    public function test_the_command_is_registered_and_runs_every_fifteen_minutes(): void
    {
        $this->assertArrayHasKey('reports:deliver', Artisan::all());

        $expression = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'reports:deliver'))
            ?->expression;

        // Every fifteen minutes, which is what `ReportSchedule::isDueAt()`'s window is matched to.
        $this->assertSame('*/15 * * * *', $expression);
    }

    /**
     * Retries are bounded, and then the owner is told — item 8.
     *
     * "A scheduled report that quietly stopped arriving is worse than one that was never set up: everybody
     * assumes the silence means nothing happened."
     */
    public function test_the_owner_is_told_after_repeated_failure(): void
    {
        Notification::fake();

        $schedule = $this->schedule();
        $delivery = ReportDelivery::query()->create([
            'report_schedule_id' => $schedule->getKey(),
            'period_key' => $schedule->periodKey(Carbon::parse(self::TODAY)),
        ]);

        foreach (range(1, ReportDelivery::MAX_ATTEMPTS) as $attempt) {
            $delivery->markFailed('The PDF engine timed out.');
        }

        $this->assertSame(ReportDelivery::MAX_ATTEMPTS, (int) $delivery->refresh()->attempts);
        $this->assertTrue($delivery->isExhausted());

        app(ReportDeliveryService::class)->giveUp($schedule, 'The PDF engine timed out.');

        Notification::assertSentTo(
            $this->owner,
            ScheduledReportFailed::class,
            fn (ScheduledReportFailed $notification): bool => str_contains($notification->error, 'timed out'),
        );
    }

    // ─────────────────────────────────────────────────────────────── the log ──

    /** The log is a report in the hub, drawn in the pane like any other — item 8. */
    public function test_the_delivery_log_is_a_report_in_the_hub(): void
    {
        $this->assertArrayHasKey('ReportDeliveries', Reports::catalogue());
        $this->assertTrue(\App\Modules\Accounting\Support\ReportPane::supports('ReportDeliveries'));
    }

    /** And it says what went out, to how many of the people it was meant for. */
    public function test_the_log_says_what_went_out(): void
    {
        Notification::fake();

        $this->invoice('INV-1', 1000);
        $this->makeUser('Accountant', 'second@test.local');

        $schedule = $this->schedule(['recipients' => ['boss@test.local', 'second@test.local']]);
        $this->makeUser('Accountant', 'boss@test.local');

        app(ReportDeliveryService::class)->deliver($schedule, Carbon::parse(self::TODAY));

        $log = ReportRenderers::render('ReportDeliveries', self::TODAY, false, []);

        $this->assertNotNull($log);
        $this->assertSame('Report Deliveries', $log['title']);
        $this->assertCount(1, $log['rows']);

        [$report, $period, $status, $to] = $log['rows'][0];

        $this->assertSame('Aged Receivables', $report);
        $this->assertStringContainsString('last_month', $period);
        $this->assertSame('Sent', $status);
        $this->assertSame('2 of 2', $to);
        $this->assertTrue($log['balanced']);
    }

    /** A failure in the log makes the log say so, in the colour the pane uses for a report that is out. */
    public function test_a_failure_shows_in_the_log(): void
    {
        $schedule = $this->schedule();

        ReportDelivery::query()->create([
            'report_schedule_id' => $schedule->getKey(),
            'period_key' => 'last_month:2027-01-01..2027-01-31',
            'status' => ReportDelivery::STATUS_FAILED,
            'error' => 'The PDF engine timed out.',
        ]);

        $log = ReportRenderers::render('ReportDeliveries', self::TODAY, false, []);

        $this->assertFalse($log['balanced']);
        $this->assertStringContainsString('FAILED', $log['note']);
        $this->assertSame('Failed', $log['rows'][0][2]);
        $this->assertStringContainsString('timed out', $log['rows'][0][5]);
    }

    // ────────────────────────────────────────────────────────── the screen ──

    /**
     * Creating one through the form sets the owner to whoever filled it in — item 2.
     *
     * Not offered as a field, because the owner is not a preference: it is the person whose access decides
     * what the rows are, so choosing somebody else would be a way to have a report rendered with their
     * permissions.
     */
    public function test_creating_a_schedule_through_the_form_sets_the_owner(): void
    {
        $author = $this->makeUser('Accountant', 'author@test.local');
        $this->actingAs($author);

        Livewire::test(CreateReportSchedule::class)
            ->fillForm([
                'report_key' => 'AgedReceivables',
                'period' => RelativePeriod::LAST_MONTH,
                'cron' => '0 7 * * 1',
                'timezone' => 'Asia/Karachi',
                'format' => ReportSchedule::FORMAT_CSV,
                'recipients' => ['boss@test.local'],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $schedule = ReportSchedule::query()->firstOrFail();

        $this->assertSame($author->getKey(), (int) $schedule->user_id);
        $this->assertSame('0 7 * * 1', $schedule->cron);
        $this->assertSame(['boss@test.local'], $schedule->recipients);
    }

    /**
     * A cron nobody can parse is refused at the form rather than at 07:00.
     *
     * The failure it prevents is the quiet kind: a schedule that lists happily, says when it will next run,
     * and is never due.
     */
    public function test_an_unparseable_timetable_is_refused(): void
    {
        Livewire::test(CreateReportSchedule::class)
            ->fillForm([
                'report_key' => 'AgedReceivables',
                'period' => RelativePeriod::LAST_MONTH,
                'cron' => 'every monday please',
                'timezone' => 'UTC',
                'format' => ReportSchedule::FORMAT_CSV,
                'recipients' => ['boss@test.local'],
            ])
            ->call('create')
            ->assertHasFormErrors(['cron']);

        $this->assertSame(0, ReportSchedule::query()->count());
    }

    /**
     * The hub's Schedule button carries the open report and its filters — item 1.
     *
     * "The same state the URL carries, so 'the schedule' and 'the link' are the same thing." A schedule form
     * that started blank would send the *default* register of the *default* account, which is a wrong report
     * rather than a missing feature — so this asserts the round trip: the pane's filters reach the schedule.
     */
    public function test_a_schedule_can_be_started_from_the_open_report(): void
    {
        // Mounted with the report open rather than selected afterwards: a page's header actions are built
        // when the component boots, so a `call()` or a `set()` does not rebuild them — the trap Phase 7's
        // own tests recorded. In a browser every property change is its own request and it does not arise.
        $link = Livewire::test(Reports::class, [
            'asOf' => self::TODAY,
            'selected' => 'PettyCashBook',
            'month' => 'January',
        ])
            ->instance()
            ->getCachedHeaderActions();

        $link = collect($link)
            ->first(fn (object $action): bool => $action->getName() === 'scheduleReport')
            ?->getUrl();

        $this->assertStringContainsString('report_key=PettyCashBook', urldecode((string) $link));
        $this->assertStringContainsString('state[month]=January', urldecode((string) $link));

        // And the create screen opens on that report, with that filter, over the form's own defaults.
        Livewire::withQueryParams(['report_key' => 'PettyCashBook', 'state' => ['month' => 'January']])
            ->test(CreateReportSchedule::class)
            ->assertFormSet([
                'report_key' => 'PettyCashBook',
                'state' => ['month' => 'January'],
                // Untouched by the link, so still the form's own.
                'period' => RelativePeriod::LAST_MONTH,
            ]);
    }

    /** The state survives the submit, because it is a field rather than a query parameter. */
    public function test_the_reports_filters_are_stored_on_the_schedule(): void
    {
        Livewire::test(CreateReportSchedule::class)
            ->fillForm([
                'report_key' => 'PettyCashBook',
                'state' => ['month' => 'January'],
                'period' => RelativePeriod::LAST_MONTH,
                'cron' => '0 7 * * 1',
                'timezone' => 'UTC',
                'format' => ReportSchedule::FORMAT_CSV,
                'recipients' => ['boss@test.local'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $schedule = ReportSchedule::query()->firstOrFail();

        $this->assertSame(['month' => 'January'], $schedule->state);
        // Which is what the renderer is handed, under the key the pane uses.
        $this->assertSame('January', $schedule->asked()['month']);
    }

    /** The list draws, with the suspension visible on it. */
    public function test_the_list_shows_a_suspended_schedule_and_why(): void
    {
        $schedule = $this->schedule();
        $schedule->suspend('The owner is no longer permitted to read reports.');

        Livewire::test(ListReportSchedules::class)
            ->assertSuccessful()
            ->assertSee('Aged Receivables')
            ->assertSee('Suspended')
            ->assertSee('no longer permitted');
    }

    /** Somebody else's schedule is not theirs to edit — the policy, not the screen, is the gate. */
    public function test_another_persons_schedule_is_not_editable(): void
    {
        $schedule = $this->schedule();

        $colleague = $this->makeUser('Accountant', 'colleague@test.local');

        $this->assertTrue($colleague->can('view', $schedule));
        $this->assertFalse($colleague->can('update', $schedule));

        // Its owner may, and so may an Administrator — somebody has to be able to stop a schedule while its
        // owner is away.
        $this->assertTrue($this->owner->can('update', $schedule));
    }

    // ───────────────────────────────────────────────── a built report's period ──

    /**
     * A built report's own period is overridden by the schedule's.
     *
     * Both carry one — a definition because Phase 6.2 stored it relative, a schedule because item 1 lists it —
     * and the one somebody set on the schedule is the one they meant. Resolving the definition's against a
     * date that is itself the end of a relative span would answer the month before the one they asked for.
     */
    public function test_the_schedules_period_overrides_the_definitions(): void
    {
        $this->invoice('INV-JAN', 1000, ['invoice_date' => '2027-01-15']);

        $definition = ReportDefinition::put('Sales', InvoiceDataset::class, [
            'columns' => ['invoice_number'],
            'period' => RelativePeriod::YEAR_TO_DATE,
        ]);

        $schedule = $this->schedule([
            'report_key' => $definition->reportKey(),
            'period' => RelativePeriod::LAST_MONTH,
        ]);

        $payload = app(ReportDeliveryService::class)->renderAs($this->owner, $schedule, Carbon::parse(self::TODAY));

        $this->assertStringContainsString('LAST MONTH', $payload['note']);
        $this->assertSame([['INV-JAN']], $payload['rows']);
    }

    // ─────────────────────────────────────────────────────────────── fixtures ──

    /** @param  array<string, mixed>  $attributes */
    private function schedule(array $attributes = []): ReportSchedule
    {
        return ReportSchedule::query()->create(array_merge([
            'user_id' => $this->owner->getKey(),
            // A coded report, so most of these tests are about delivery rather than about a dataset.
            'report_key' => 'AgedReceivables',
            'period' => RelativePeriod::LAST_MONTH,
            // CSV throughout: the PDF path is `ReportExportActionsTest`'s, and rendering one per test would
            // measure the engine rather than the schedule.
            'format' => ReportSchedule::FORMAT_CSV,
            'cron' => '0 7 * * *',
            'timezone' => 'Asia/Karachi',
            'recipients' => ['boss@test.local'],
            'is_active' => true,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoice(string $number, float $total, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_number' => $number,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_ISSUED,
            'contact_id' => Contact::create(['name' => 'Customer '.$number, 'kind' => Contact::KIND_CUSTOMER])->id,
            'invoice_date' => '2027-01-10',
            'due_date' => '2027-01-25',
            'subtotal' => $total,
            'total' => $total,
        ], $attributes));
    }

    private function employeeFor(string $email, string $name): Employee
    {
        $user = $this->makeUser('Employee', $email);

        return Employee::create([
            'employee_id' => 'E-'.mb_substr(md5($email), 0, 5),
            'name' => $name,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2026-07-01',
            'user_id' => $user->getKey(),
        ]);
    }

    private function payslip(Employee $employee, string $month): Payslip
    {
        return Payslip::create([
            'employee_id' => $employee->getKey(),
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }
}
