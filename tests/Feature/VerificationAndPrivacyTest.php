<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeVerification;
use App\Modules\Employees\Models\LetterTemplate;
use App\Modules\Employees\Services\EmployeeErasure;
use App\Modules\Employees\Services\VerificationCodes;
use App\Modules\Employees\Support\EmployeeChecks;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Support\DocumentRequirements;
use App\Modules\Payroll\Models\Payslip;
use App\Support\TenantSettings;
use App\Support\VerificationChecks;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Verification and privacy — docs/saas-launch-plan.md §14.8.
 *
 * The rule the section opens with is the first thing tested here, because it is the
 * one that turns a feature into a locked door if it is wrong: **the gate never blocks
 * for a check the company cannot perform.**
 */
class VerificationAndPrivacyTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $hr;

    private User $staff;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->makeUser('Administrator', 'privacy-hr@test.local');
        $this->actingAs($this->hr);
        $this->setCurrentTenant();

        $this->staff = $this->makeUser('Employee', 'privacy-staff@test.local');
        $this->staff->forceFill(['name' => 'Ayesha Khan'])->save();

        $this->employee = Employee::create([
            'user_id' => $this->staff->getKey(), 'employee_id' => 'EMP-P1', 'name' => 'Ayesha Khan',
            'gender' => 'Female', 'is_active' => true, 'date_of_joining' => '2021-04-01',
            'phone' => '0300 1234567', 'emergency_contact_phone' => '0321 7654321',
            'nic' => '35202-1234567-1', 'personal_email' => 'ayesha@home.test',
            'bank_account_no' => '0123456789', 'iban_no' => 'PK36SCBL0000001123456702',
            'current_address' => ['line_1' => '12 Gulberg', 'city' => 'Lahore'],
        ]);
    }

    private function fakeWhatsApp(): object
    {
        $fake = new class implements WhatsAppSender
        {
            /** @var array<int, array{to: string, text: string}> */
            public array $sent = [];

            public function sendDocument(string $to, \App\Support\WhatsApp\WhatsAppDocument $document, string $caption): string
            {
                return 'doc';
            }

            public function sendText(string $to, string $text): string
            {
                $this->sent[] = ['to' => $to, 'text' => $text];

                return 'msg-'.count($this->sent);
            }
        };

        $this->app->instance(WhatsAppSender::class, $fake);

        return $fake;
    }

    // ------------------------------------------------------------------ the rule

    public function test_a_check_the_company_cannot_perform_is_not_offered_and_never_blocks(): void
    {
        // Nothing is configured: no WhatsApp provider, no address letter, no required
        // documents. All three checks exist and none of them can be done.
        $this->assertSame([], VerificationChecks::offered());

        // Switched on anyway, which is the state a company lands in when its WhatsApp
        // credentials lapse a week after it ticked the box.
        app(TenantSettings::class)->set('verification.required', ['employee.phone', 'employee.address', 'lifecycle.documents']);

        $this->assertSame([], VerificationChecks::required(), 'a check with no means behind it is not required');
        $this->assertSame([], VerificationChecks::outstandingFor($this->staff), 'and cannot be why anybody is held up');
    }

    public function test_a_check_is_offered_once_the_means_exists(): void
    {
        $this->fakeWhatsApp();

        $this->assertArrayHasKey('employee.phone', VerificationChecks::offered());
        $this->assertArrayNotHasKey('employee.address', VerificationChecks::offered(), 'no letter template yet');

        LetterTemplate::create(['kind' => LetterTemplate::KIND_ADDRESS_VERIFICATION, 'locale' => 'en', 'name' => 'Address', 'body' => 'Your code is {code}.']);

        $this->assertArrayHasKey('employee.address', VerificationChecks::offered());
    }

    // ------------------------------------------------------- phone and address

    public function test_a_phone_code_goes_to_the_number_being_proved_and_is_never_stored(): void
    {
        $whatsapp = $this->fakeWhatsApp();

        app(VerificationCodes::class)->issue($this->employee, EmployeeVerification::KIND_PHONE);

        $this->assertCount(1, $whatsapp->sent);
        $this->assertSame('923001234567', $whatsapp->sent[0]['to'], 'E.164, and the number on the record');

        preg_match('/\b(\d{6})\b/', $whatsapp->sent[0]['text'], $code);
        $this->assertNotEmpty($code, 'the message carries a six-digit code');

        $row = EmployeeVerification::sole();
        $this->assertNotSame($code[1], $row->code_hash, 'the code itself is not in the table');
        $this->assertTrue(Hash::check($code[1], (string) $row->code_hash));
        $this->assertNull($row->verified_at);

        $this->assertNull(app(VerificationCodes::class)->check($this->employee, EmployeeVerification::KIND_PHONE, $code[1]));

        $row->refresh();
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->code_hash, 'a code that has worked is spent');
    }

    public function test_a_wrong_code_is_counted_and_the_code_dies_after_five(): void
    {
        $this->fakeWhatsApp();
        app(VerificationCodes::class)->issue($this->employee, EmployeeVerification::KIND_PHONE);

        $codes = app(VerificationCodes::class);

        for ($try = 1; $try <= EmployeeVerification::MAX_ATTEMPTS; $try++) {
            $this->assertSame('That is not the code.', $codes->check($this->employee, EmployeeVerification::KIND_PHONE, '000000'));
        }

        $this->assertSame(
            'Too many wrong tries. Send a new code.',
            $codes->check($this->employee, EmployeeVerification::KIND_PHONE, '000000'),
            'a six-digit code is only safe because the guesses run out',
        );
    }

    public function test_changing_the_number_un_proves_it(): void
    {
        $whatsapp = $this->fakeWhatsApp();
        app(VerificationCodes::class)->issue($this->employee, EmployeeVerification::KIND_PHONE);
        preg_match('/\b(\d{6})\b/', $whatsapp->sent[0]['text'], $code);
        app(VerificationCodes::class)->check($this->employee, EmployeeVerification::KIND_PHONE, $code[1]);

        $this->employee->refresh()->load('verifications');
        $this->assertTrue(EmployeeChecks::proved($this->employee)[EmployeeVerification::KIND_PHONE]);

        $this->employee->update(['phone' => '0333 9999999']);
        $this->employee->refresh()->load('verifications');

        $this->assertFalse(
            EmployeeChecks::proved($this->employee)[EmployeeVerification::KIND_PHONE],
            'the proof was about a value, and the value has changed',
        );
    }

    public function test_the_address_code_comes_back_as_a_letter_to_post(): void
    {
        LetterTemplate::create([
            'kind' => LetterTemplate::KIND_ADDRESS_VERIFICATION, 'locale' => 'en', 'name' => 'Address check',
            'subject' => 'Confirming your address', 'body' => 'Your code is {code}. It is for {address}.',
        ]);

        $letter = app(VerificationCodes::class)->issue($this->employee, EmployeeVerification::KIND_ADDRESS);

        $this->assertNotNull($letter, 'an address is proved by something arriving at it');
        $this->assertStringContainsString('12 Gulberg, Lahore', $letter->html());
        $this->assertMatchesRegularExpression('/Your code is \d{6}\./', $letter->html());
    }

    public function test_a_check_with_nothing_on_the_record_to_check_holds_nobody_up(): void
    {
        $this->fakeWhatsApp();
        app(TenantSettings::class)->set('verification.required', ['employee.emergency_phone']);

        $this->employee->update(['emergency_contact_phone' => null]);

        $this->assertSame(
            [],
            VerificationChecks::outstandingFor($this->staff),
            'somebody with no emergency contact has not refused to confirm one',
        );
    }

    public function test_somebody_with_no_employee_record_is_not_held_up(): void
    {
        $this->fakeWhatsApp();
        app(TenantSettings::class)->set('verification.required', ['employee.phone']);

        $this->assertSame([], VerificationChecks::outstandingFor($this->hr));
        $this->assertNotSame([], VerificationChecks::outstandingFor($this->staff));
    }

    // ------------------------------------------------------------------ the gate

    public function test_the_gate_sends_somebody_outstanding_to_the_one_page_that_clears_it(): void
    {
        $this->fakeWhatsApp();
        app(TenantSettings::class)->set('verification.required', ['employee.phone']);

        $this->actingAs($this->staff);

        $confirm = \App\Modules\Employees\Filament\Pages\ConfirmDetails::getUrl(tenant: $this->tenant);

        // Everything else goes to the page...
        $this->get(\App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index', tenant: $this->tenant))
            ->assertRedirect($confirm);

        // ...and the page itself does not go to itself.
        $this->get($confirm)->assertSuccessful();
    }

    public function test_the_gate_lets_everybody_through_once_the_check_is_done(): void
    {
        $whatsapp = $this->fakeWhatsApp();
        app(TenantSettings::class)->set('verification.required', ['employee.phone']);

        app(VerificationCodes::class)->issue($this->employee, EmployeeVerification::KIND_PHONE);
        preg_match('/\b(\d{6})\b/', $whatsapp->sent[0]['text'], $code);
        app(VerificationCodes::class)->check($this->employee, EmployeeVerification::KIND_PHONE, $code[1]);

        $this->actingAs($this->staff);

        $this->get(\App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index', tenant: $this->tenant))
            ->assertSuccessful();
    }

    public function test_the_gate_holds_nobody_when_the_company_requires_nothing(): void
    {
        $this->fakeWhatsApp();
        $this->actingAs($this->staff);

        $this->get(\App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index', tenant: $this->tenant))
            ->assertSuccessful();
    }

    // -------------------------------------------------------- required documents

    public function test_the_compliance_state_of_each_required_kind(): void
    {
        app(TenantSettings::class)->set('lifecycle.documents.required', ['cnic', 'passport', 'visa']);

        EmployeeDocument::create(['employee_id' => $this->employee->getKey(), 'kind' => 'cnic', 'verified_at' => now()]);
        EmployeeDocument::create(['employee_id' => $this->employee->getKey(), 'kind' => 'passport']);

        $this->assertSame(
            ['cnic' => 'verified', 'passport' => 'unverified', 'visa' => 'missing'],
            DocumentRequirements::stateFor($this->employee),
        );

        // Missing counts what is not held *or* usable; something awaiting review is held.
        $this->assertSame(['Visa'], DocumentRequirements::missingFor($this->employee));
    }

    public function test_a_refused_document_does_not_count_as_held(): void
    {
        app(TenantSettings::class)->set('lifecycle.documents.required', ['cnic']);

        EmployeeDocument::create([
            'employee_id' => $this->employee->getKey(), 'kind' => 'cnic',
            'rejected_at' => now(), 'rejection_reason' => 'The back of the card is missing.',
        ]);

        $this->assertSame(['cnic' => 'rejected'], DocumentRequirements::stateFor($this->employee));
        $this->assertSame(['CNIC'], DocumentRequirements::missingFor($this->employee));
    }

    public function test_nobody_verifies_their_own_document(): void
    {
        $document = EmployeeDocument::create(['employee_id' => $this->employee->getKey(), 'kind' => 'cnic']);

        $this->assertTrue($this->hr->can('verify', $document));
        $this->assertFalse($this->staff->can('verify', $document), 'a document only its owner has looked at is unverified');
    }

    // ---------------------------------------------------- subject access, erasure

    public function test_a_disclosure_gathers_what_every_module_holds(): void
    {
        Payslip::create(['employee_id' => $this->employee->getKey(), 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July', 'total_working_days' => 22, 'paid_days' => 22]);
        EmployeeDocument::create(['employee_id' => $this->employee->getKey(), 'kind' => 'cnic', 'number' => '35202-1234567-1']);

        $disclosure = app(EmployeeErasure::class)->disclose($this->employee);

        $this->assertArrayHasKey('The employee record', $disclosure);
        $this->assertArrayHasKey('Documents on file', $disclosure);
        $this->assertArrayHasKey('Payslips', $disclosure);
        $this->assertSame('Ayesha Khan', $disclosure['The employee record']['name_on_account']);
        $this->assertCount(1, $disclosure['Payslips']);
    }

    public function test_erasure_empties_the_person_and_keeps_the_payslip(): void
    {
        $payslip = Payslip::create(['employee_id' => $this->employee->getKey(), 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July', 'total_working_days' => 22, 'paid_days' => 22]);

        $this->employee->update(['is_active' => false]);
        app(EmployeeErasure::class)->erase($this->employee->refresh());

        $erased = $this->employee->refresh();

        foreach (['phone', 'personal_email', 'nic', 'bank_account_no', 'iban_no', 'emergency_contact_phone', 'current_address'] as $field) {
            $this->assertNull($erased->{$field}, "{$field} should have been emptied");
        }

        $this->assertNull($erased->user_id, 'the account is unlinked, never deleted: it may belong to other companies');
        $this->assertNotNull($erased->anonymised_at);
        $this->assertSame('Erased (EMP-P1)', $erased->name);
        $this->assertSame('EMP-P1', $erased->employee_id, 'the code stays, or the payslips point at nothing');

        $this->assertTrue($payslip->fresh()->exists, 'a payslip is a statutory record and erasure never touches one');
        $this->assertSame(0, EmployeeVerification::count(), 'the proofs go with the values they proved');
    }

    public function test_erasure_refuses_somebody_still_employed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still active');

        app(EmployeeErasure::class)->erase($this->employee);
    }

    public function test_erasure_happens_once(): void
    {
        $this->employee->update(['is_active' => false]);
        app(EmployeeErasure::class)->erase($this->employee->refresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been erased');

        app(EmployeeErasure::class)->erase($this->employee->refresh());
    }

    public function test_only_the_privacy_permission_discloses_or_erases(): void
    {
        $this->employee->update(['is_active' => false]);

        $this->assertTrue($this->hr->can('disclose', $this->employee->refresh()));
        $this->assertFalse($this->staff->can('disclose', $this->employee->refresh()));
        $this->assertFalse($this->staff->can('erase', $this->employee->refresh()));
    }
}
