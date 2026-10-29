<?php

namespace Tests\Feature;

use App\Modules\Core\Models\MessageLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\NotificationPreferences;
use App\Modules\Employees\Listeners\IssueEventLetter;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Employees\Models\LetterTemplate;
use App\Modules\Employees\Services\TemplatedLetters;
use App\Notifications\DocumentIssued;
use App\Support\WhatsApp\LoggingWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Letters the company words for itself, the queue employees ask through, and the log
 * of what was actually sent — docs/saas-launch-plan.md §14.7.
 */
class LettersAndMessagesTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $hr;

    private User $staff;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->makeUser('Administrator', 'hr@test.local');
        $this->actingAs($this->hr);
        $this->setCurrentTenant();

        $this->staff = $this->makeUser('Employee', 'staff@test.local');
        // The letter prints the name on the user account, which is the one an
        // employee record defers to — see Employee::fullName().
        $this->staff->forceFill(['name' => 'Ayesha Khan'])->save();
        $this->employee = Employee::create([
            'user_id' => $this->staff->getKey(), 'employee_id' => 'EMP-1', 'name' => 'Ayesha Khan',
            'gender' => 'Female', 'is_active' => true, 'department' => 'IT', 'designation' => 'Backend Developer',
            'date_of_joining' => '2021-04-01',
        ]);
    }

    private function template(string $kind = 'increment', array $overrides = []): LetterTemplate
    {
        return LetterTemplate::create(array_merge([
            'kind' => $kind,
            'locale' => 'en',
            'name' => 'Standard '.$kind,
            'subject' => 'Revision of salary with effect from {effective_from}',
            'body' => "Dear {employee},\n\nYour salary as {designation} is revised from {old_value} to {new_value} with effect from {effective_from}.\n\nIssued by {company} on {today}.",
        ], $overrides));
    }

    public function test_a_template_fills_its_placeholders_from_the_record_and_leaves_unknown_ones_blank(): void
    {
        $template = $this->template(overrides: ['body' => 'Dear {employee}, a {designation} since {date_of_joining}. {nonsense}']);

        $rendered = $template->render(app(TemplatedLetters::class)->values($this->employee));

        $this->assertSame('Dear Ayesha Khan, a Backend Developer since 01 April 2021. ', $rendered);
        $this->assertSame(
            'Revision of salary with effect from 01 May 2026',
            $template->renderSubject(app(TemplatedLetters::class)->values($this->employee, ['effective_from' => '01 May 2026'])),
        );
    }

    public function test_an_event_letter_is_emailed_when_the_company_has_written_one_and_nothing_goes_out_when_it_has_not(): void
    {
        Notification::fake();

        // No template: the increment still happened, and no letter is invented for it.
        $this->assertFalse(app(IssueEventLetter::class)->issue($this->employee, 'increment', ['new_value' => '115,000.00']));
        Notification::assertNothingSent();

        $this->template();

        $this->assertTrue(app(IssueEventLetter::class)->issue($this->employee, 'increment', [
            'effective_from' => '01 May 2026', 'old_value' => '100,000.00', 'new_value' => '115,000.00',
        ]));

        Notification::assertSentTo($this->staff, DocumentIssued::class, function (DocumentIssued $notification): bool {
            $this->assertStringContainsString('increment-letter-ayesha-khan', $notification->filename);

            return $notification->document === 'Increment letter';
        });
    }

    public function test_only_the_four_event_kinds_issue_themselves(): void
    {
        Notification::fake();
        $this->template('noc');

        // A no-objection certificate is a template like any other, but nothing in the
        // application decides on its own that somebody needs one.
        $this->assertFalse(app(IssueEventLetter::class)->issue($this->employee, 'noc'));
        Notification::assertNothingSent();
    }

    public function test_rendering_a_letter_the_company_has_not_written_says_so_rather_than_producing_a_blank_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No-objection certificate');

        app(TemplatedLetters::class)->renderPdf($this->employee, 'noc');
    }

    public function test_an_inactive_template_is_not_used(): void
    {
        $this->template('noc', ['is_active' => false]);

        $this->assertNull(LetterTemplate::for('noc'));
    }

    public function test_a_request_is_issued_or_refused_and_the_refusal_keeps_its_reason(): void
    {
        $request = LetterRequest::create(['employee_id' => $this->employee->getKey(), 'kind' => 'noc', 'purpose' => 'a visa application']);

        $this->assertTrue($request->isPending());
        $this->assertSame($this->hr->getKey(), $request->requested_by, 'Stamped on create, so a request always says who asked.');
        $this->assertSame(1, LetterRequest::query()->pending()->count());

        $request->update(['status' => LetterRequest::STATUS_REFUSED, 'decided_by' => $this->hr->getKey(), 'decided_at' => now(), 'refusal_reason' => 'Ask the embassy for the form first.']);

        $this->assertFalse($request->fresh()->isPending());
        $this->assertSame('Ask the embassy for the form first.', $request->fresh()->refusal_reason);
        $this->assertSame(0, LetterRequest::query()->pending()->count());
    }

    public function test_an_employee_sees_their_own_request_and_not_somebody_elses(): void
    {
        $other = Employee::create(['employee_id' => 'EMP-2', 'name' => 'Someone Else', 'gender' => 'Male', 'is_active' => true, 'date_of_joining' => '2022-01-01']);

        $mine = LetterRequest::create(['employee_id' => $this->employee->getKey(), 'kind' => 'noc']);
        $theirs = LetterRequest::create(['employee_id' => $other->getKey(), 'kind' => 'noc']);

        $this->actingAs($this->staff);

        $this->assertTrue($this->staff->can('view', $mine));
        $this->assertFalse($this->staff->can('view', $theirs));
        $this->assertTrue($this->staff->can('create', LetterRequest::class), 'Asking for a letter is not a permission.');
        $this->assertFalse($this->staff->can('update', $mine), 'Deciding it is.');
    }

    public function test_every_mail_that_goes_out_is_logged_by_its_envelope_and_never_its_body(): void
    {
        Mail::raw('The net salary is 182,500.00', fn ($message) => $message->to('staff@test.local')->subject('Your payslip for March 2026'));

        $log = MessageLog::query()->sole();

        $this->assertSame(MessageLog::CHANNEL_MAIL, $log->channel);
        $this->assertSame('staff@test.local', $log->recipient);
        $this->assertSame('Your payslip for March 2026', $log->subject);
        $this->assertSame(MessageLog::STATUS_SENT, $log->status);
        $this->assertNotNull($log->sent_at);

        $this->assertStringNotContainsString('182,500.00', (string) json_encode($log->toArray()),
            'The log keeps the envelope. A log holding the figures would be a second copy of every payslip.');
    }

    public function test_a_whatsapp_message_is_logged_whichever_driver_sent_it(): void
    {
        $sender = app(WhatsAppSender::class);

        $this->assertInstanceOf(LoggingWhatsAppSender::class, $sender,
            'Every driver is wrapped, so none of them has to remember to log.');

        $sender->sendText('923001234567', 'Your payslip for March is ready.');

        $log = MessageLog::query()->where('channel', MessageLog::CHANNEL_WHATSAPP)->sole();

        $this->assertSame('923001234567', $log->recipient);
        $this->assertSame(MessageLog::STATUS_SENT, $log->status);
    }

    public function test_a_person_can_stop_a_category_of_email_but_not_the_ones_that_keep_their_access(): void
    {
        NotificationPreferences::set($this->staff, ['monitoring', 'reports', 'account']);

        $this->staff->refresh();

        $this->assertSame(['monitoring', 'reports'], NotificationPreferences::silenced($this->staff),
            'Account email is not offered, so asking to silence it is ignored rather than honoured.');
        $this->assertFalse(NotificationPreferences::wants($this->staff, 'monitoring'));
        $this->assertTrue(NotificationPreferences::wants($this->staff, 'payroll'));
        $this->assertTrue(NotificationPreferences::wants($this->staff, 'account'));
    }

    public function test_a_silenced_category_stops_the_email_and_leaves_the_bell_alone(): void
    {
        Mail::fake();
        $this->template('increment');

        NotificationPreferences::set($this->staff, ['documents']);
        $this->staff->refresh();

        // DocumentIssued is a documents-category mail, so this one is dropped on the way out.
        $this->assertTrue(app(IssueEventLetter::class)->issue($this->employee, 'increment', ['new_value' => '115,000.00']));

        Mail::assertNothingSent();
        $this->assertSame(0, MessageLog::query()->count(), 'Nothing was sent, so nothing is logged as sent.');
    }
}
