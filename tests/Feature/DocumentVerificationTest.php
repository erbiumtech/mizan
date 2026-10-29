<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Services\IncomeCertificate;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Support\DocumentVerification;
use App\Support\TenantSettings;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Tamper-evident documents — docs/saas-launch-plan.md §7.5. Two gates; the
 * hash recomputed from the live record; matches, does not match, or no such
 * document; and never more than was printed.
 */
class DocumentVerificationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const TOKEN = 'verify-token-123';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->actingAs($this->makeUser('Administrator', 'hr-verify@test.local'));
        $this->setCurrentTenant();
        $this->tenant->makeCurrent();

        app(TenantSettings::class)->set('company.legal_name', 'ERBIUMTECH (SMC-Private) Limited');
        app(TenantSettings::class)->set('company.address', 'Lahore');
        app(TenantSettings::class)->set('company.ntn', '1234567-8');
        app(TenantSettings::class)->set('company.signatory_name', 'Muzafar Ali');

        // The letters print the login's name; the test's name has to be on it.
        $login = $this->makeUser('Employee', 'verified@test.local');
        $login->update(['name' => 'Muhammad Hammad']);

        $this->employee = Employee::create([
            'user_id' => $login->getKey(),
            'employee_id' => 'EMP-014', 'name' => 'Muhammad Hammad', 'gender' => 'Male', 'is_active' => true,
            'designation' => 'Developer', 'department' => 'IT', 'date_of_joining' => '2024-03-01',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->getKey(), 'fiscal_year_id' => $this->fiscalYear->getKey(),
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 200000, 'medical_allowance' => 20000,
        ]);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        parent::tearDown();
    }

    private function openTheGates(): void
    {
        app(TenantSettings::class)->set('documents.verification.enabled', true);
        app(TenantSettings::class)->set('documents.verification.token', self::TOKEN);
    }

    public function test_no_code_is_printed_and_the_page_is_404_while_verification_is_off(): void
    {
        $facts = app(IncomeCertificate::class)->verificationFacts($this->employee);

        $this->assertNull(DocumentVerification::urlFor('income-certificate', $this->employee->getKey(), $facts));
        $this->assertNull(app(IncomeCertificate::class)->data($this->employee)['verification_qr']);

        $hash = DocumentVerification::hashFor('income-certificate', $this->employee->getKey(), $facts);
        Company::forgetCurrent();
        $this->get("/verify/{$this->tenant->slug}/".self::TOKEN."/income-certificate/{$this->employee->getKey()}/{$hash}")->assertNotFound();
    }

    public function test_a_printed_code_verifies_and_a_changed_record_does_not(): void
    {
        $this->openTheGates();

        $letters = app(IncomeCertificate::class);
        $facts = $letters->verificationFacts($this->employee);
        $url = DocumentVerification::urlFor('income-certificate', $this->employee->getKey(), $facts);

        $this->assertNotNull($url);
        $this->assertStringContainsString('/verify/'.$this->tenant->slug.'/'.self::TOKEN.'/income-certificate/', $url);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $letters->data($this->employee)['verification_qr']);
        $this->assertStringContainsString('Scan to verify', $letters->renderPdf($this->employee)->html());

        Company::forgetCurrent();
        $this->get($url)
            ->assertOk()
            ->assertSee('matches the company')
            ->assertSee('Muhammad Hammad')
            ->assertSee('220,000.00');

        // A wrong token is nothing, not a hint.
        $this->get(str_replace(self::TOKEN, 'other-token', $url))->assertNotFound();

        // The record changes after printing: the old code no longer matches, and the
        // page says so without saying what the record holds now.
        $this->tenant->makeCurrent();
        $this->employee->update(['designation' => 'Lead Developer']);
        Company::forgetCurrent();
        $this->get($url)->assertOk()->assertSee('does not match')->assertDontSee('Lead Developer')->assertDontSee('220,000');

        // No such record at all.
        $this->get(preg_replace('#/income-certificate/\d+/#', '/income-certificate/999999/', $url))->assertOk()->assertSee('No such document');

        // A kind nobody registered.
        $this->get(str_replace('/income-certificate/', '/mystery-letter/', $url))->assertNotFound();
    }

    public function test_a_payslip_carries_a_code_for_its_own_figures(): void
    {
        $this->openTheGates();
        $this->tenant->makeCurrent();

        $payslip = Payslip::create([
            'employee_id' => $this->employee->getKey(), 'month' => 'August', 'fiscal_year_id' => $this->fiscalYear->getKey(),
        ]);

        $facts = PayslipService::verificationFacts($payslip->fresh());
        $this->assertSame('EMP-014', $facts['employee_code']);
        $this->assertSame('August', $facts['month']);
        $this->assertArrayHasKey('net_salary', $facts);

        $url = DocumentVerification::urlFor('payslip', $payslip->getKey(), $facts);
        $this->assertNotNull($url);
        $this->assertStringContainsString('Scan to verify', app(PayslipService::class)->renderPdf($payslip->fresh())->html());

        Company::forgetCurrent();
        $this->get($url)->assertOk()->assertSee('matches the company')->assertSee('August');
    }

    public function test_every_registered_kind_answers_missing_for_an_unknown_id(): void
    {
        $this->openTheGates();
        $this->tenant->makeCurrent();

        $this->assertEqualsCanonicalizing(
            ['payslip', 'income-certificate', 'experience-letter', 'appointment-letter', 'offer-letter', 'employment-contract'],
            DocumentVerification::kinds(),
        );

        foreach (DocumentVerification::kinds() as $kind) {
            $this->assertSame(DocumentVerification::MISSING, DocumentVerification::verify($kind, 999999, str_repeat('0', 32))['verdict'], $kind);
        }
    }
}
