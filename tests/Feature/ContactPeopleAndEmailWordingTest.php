<?php

namespace Tests\Feature;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\ContactPerson;
use App\Modules\Payroll\Models\Payslip;
use App\Notifications\PayslipIssued;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Two small gaps from the plan: several people at a client, and the wording of the
 * emails.
 *
 * A Contact carried one email and one phone, so a company was one person — the clerk
 * who pays, the manager who queries a line and the director who signs were all the
 * same row or nowhere. And notification text was in PHP, so "Please open it and
 * confirm the figures are right" reached every employee of every company in exactly
 * those words and changing them meant a deployment.
 */
class ContactPeopleAndEmailWordingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'small@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_CUSTOMER,
            'email' => 'accounts@erbium.example',
            'is_active' => true,
        ]);
    }

    private function person(array $attributes = []): ContactPerson
    {
        return ContactPerson::create(array_merge([
            'contact_id' => $this->client->id,
            'name' => 'Anna Meier',
            'title' => 'Accounts Payable',
            'email' => 'anna@erbium.example',
        ], $attributes));
    }

    // ---- People at a client ------------------------------------------------

    public function test_a_client_can_have_several_people(): void
    {
        $this->person();
        $this->person(['name' => 'Peter Roth', 'title' => 'Managing Director', 'email' => 'peter@erbium.example']);

        $this->assertSame(2, $this->client->people()->count());
    }

    public function test_only_one_person_is_the_main_contact(): void
    {
        $anna = $this->person(['is_primary' => true]);
        $peter = $this->person(['name' => 'Peter Roth', 'email' => 'peter@erbium.example', 'is_primary' => true]);

        $this->assertSame(1, $this->client->people()->where('is_primary', true)->count());
        $this->assertTrue($peter->fresh()->is_primary);
        $this->assertFalse($anna->fresh()->is_primary);
    }

    /**
     * Adding people changes who is written to; adding none changes nothing, which is
     * what keeps every existing client working.
     */
    public function test_correspondence_goes_to_the_main_contact_when_there_is_one(): void
    {
        $this->assertSame('accounts@erbium.example', $this->client->correspondenceEmail());

        $this->person(['is_primary' => true]);

        $this->assertSame('anna@erbium.example', $this->client->fresh()->correspondenceEmail());
    }

    public function test_a_person_without_an_email_does_not_take_over_correspondence(): void
    {
        // Somebody recorded for their phone number is not an address to write to.
        $this->person(['is_primary' => true, 'email' => null]);

        $this->assertSame('accounts@erbium.example', $this->client->fresh()->correspondenceEmail());
    }

    public function test_the_label_names_their_role_when_they_have_one(): void
    {
        $this->assertSame('Anna Meier (Accounts Payable)', $this->person()->label());
        $this->assertSame('Anna Meier', $this->person(['title' => null])->label());
    }

    public function test_people_go_with_the_client(): void
    {
        $this->person();
        $id = $this->client->id;

        $this->client->delete();

        $this->assertSame(0, ContactPerson::where('contact_id', $id)->count());
    }

    // ---- The wording of the emails -----------------------------------------

    private function payslip(): Payslip
    {
        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'wording@test.local')->id,
            'employee_id' => 'EMP-W',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);

        return Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    private function mailFor(Payslip $payslip): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new PayslipIssued($payslip))->toMail($payslip->employee->user);
    }

    public function test_with_no_template_the_shipped_wording_is_sent(): void
    {
        // Nothing is required of a company: the feature adds a way to change the words,
        // not an obligation to supply them.
        $mail = $this->mailFor($this->payslip());

        $this->assertStringContainsString('Your payslip for July', $mail->subject);
        $this->assertStringContainsString(
            'confirm the figures are right',
            collect($mail->introLines)->implode(' '),
        );
    }

    public function test_a_template_can_change_only_the_subject(): void
    {
        // A company rewording a subject line should not have to retype the body.
        EmailTemplate::create([
            'key' => 'payslip_issued',
            'subject' => 'Payslip — {period}',
        ]);

        $mail = $this->mailFor($this->payslip());

        $this->assertSame('Payslip — July 2026-2027', $mail->subject);
        $this->assertStringContainsString(
            'confirm the figures are right',
            collect($mail->introLines)->implode(' '),
            'the body is untouched',
        );
    }

    public function test_a_template_body_replaces_the_shipped_paragraphs(): void
    {
        // Instead of, not as well as: rewording an email means saying something else.
        EmailTemplate::create([
            'key' => 'payslip_issued',
            'body' => "Salam {employee_name},\nYour {period} salary of {net_salary} has been transferred.",
        ]);

        $body = collect($this->mailFor($this->payslip())->introLines)->implode(' ');

        $this->assertStringContainsString('has been transferred', $body);
        $this->assertStringNotContainsString('confirm the figures are right', $body);
    }

    public function test_placeholders_are_filled(): void
    {
        EmailTemplate::create([
            'key' => 'payslip_issued',
            'body' => 'Net pay: {net_salary} for {period}.',
        ]);

        $payslip = $this->payslip();
        $body = collect($this->mailFor($payslip)->introLines)->implode(' ');

        $this->assertStringContainsString(number_format((float) $payslip->fresh()->net_salary, 2), $body);
        $this->assertStringContainsString('July 2026-2027', $body);
        $this->assertStringNotContainsString('{net_salary}', $body);
    }

    /**
     * Left visible rather than blanked: a stray {employee_nam} in a test email is a
     * better failure than a sentence with a hole in it reaching an employee.
     */
    public function test_an_unknown_placeholder_is_left_alone(): void
    {
        EmailTemplate::create([
            'key' => 'payslip_issued',
            'body' => 'Hello {employee_nam}, your payslip is attached.',
        ]);

        $this->assertStringContainsString(
            '{employee_nam}',
            collect($this->mailFor($this->payslip())->introLines)->implode(' '),
        );
    }

    public function test_switching_a_template_off_restores_the_shipped_wording(): void
    {
        $template = EmailTemplate::create([
            'key' => 'payslip_issued',
            'subject' => 'Payslip — {period}',
        ]);

        $template->update(['is_active' => false]);

        $this->assertStringContainsString('Your payslip for July', $this->mailFor($this->payslip())->subject);
    }

    public function test_a_closing_is_added_after_the_body(): void
    {
        EmailTemplate::create([
            'key' => 'payslip_issued',
            'closing' => 'Questions? Reply to this email.',
        ]);

        $lines = collect($this->mailFor($this->payslip())->introLines);

        $this->assertStringContainsString('confirm the figures are right', $lines->implode(' '));
        $this->assertStringContainsString('Reply to this email', $lines->last());
    }

    public function test_the_pdf_is_attached_whatever_the_wording(): void
    {
        // The wording is configurable; the payslip going with it is not.
        EmailTemplate::create(['key' => 'payslip_issued', 'body' => 'See attached.']);

        $this->assertNotEmpty($this->mailFor($this->payslip())->rawAttachments);
    }
}
