<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Employees\Services\IncomeCertificate;
use App\Support\TenantSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * "This person works here and this is what we pay them" — as a letter a bank or an embassy will act on.
 *
 * Two things carry this file, and both are about a document being read by somebody who cannot check it:
 *
 *  - **The figure is the recurring package**, not what a month happened to hold. A bonus printed as "gross
 *    monthly salary" is a promise the company has not made and a loan instalment somebody cannot pay.
 *  - **It refuses rather than guesses.** No CNIC, no joining date, no salary on file or a blank letterhead
 *    means no letter, with the gaps named. A certificate reading "PKR 0" looks official and is wrong.
 */
class IncomeCertificateTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'hr@test.local'));
        $this->setCurrentTenant();

        $this->letterhead();

        $bank = Bank::create(['bank_code' => 'TSTB', 'bank_name' => 'Test Bank', 'bank_short_code' => 'TST']);

        // The name has to be on the *user*: `Employee::fullName()` prefers the linked login, which is what
        // the panel labels this person by and so what the certificate must say.
        $login = $this->makeUser('Employee', 'hammad@test.local');
        $login->update(['name' => 'Muhammad Hammad']);

        $this->employee = Employee::create([
            'user_id' => $login->getKey(),
            'employee_id' => 'EMP-014',
            'name' => 'Muhammad Hammad',
            'gender' => 'Male',
            'is_active' => true,
            'nic' => '35201-1234567-1',
            'designation' => 'Backend Developer',
            'department' => 'IT',
            'employment_type' => 'permanent',
            'date_of_joining' => '2024-03-01',
            'address_line_1' => 'House 12, Street 4',
            'address_line_2' => 'Lahore',
            'bank_id' => $bank->getKey(),
            'bank_account_no' => '0001112223334',
        ]);
    }

    private function letterhead(array $overrides = []): void
    {
        $settings = app(TenantSettings::class);

        foreach ([
            'company.legal_name' => 'ERBIUMTECH (SMC-Private) Limited',
            'company.address' => 'Office 5, Gulberg III, Lahore',
            'company.ntn' => '1234567-8',
            'company.signatory_name' => 'Muzafar Ali',
            'company.registration_no' => '0123456',
            'company.email' => 'hr@erbiumtech.test',
            'company.website' => 'www.erbiumtech.test',
        ] + $overrides as $key => $value) {
            $settings->set($key, $overrides[$key] ?? $value);
        }
    }

    private function package(array $overrides = []): EmployeeSetting
    {
        return EmployeeSetting::create([
            'employee_id' => $this->employee->getKey(),
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'medical_allowance' => 20000,
            'device_allowance' => 5000,
            'petrol_allowance' => 25000,
            'bonus' => 100000,
            'advances' => 10000,
            ...$overrides,
        ]);
    }

    public function test_the_monthly_figure_is_the_recurring_package_and_nothing_else(): void
    {
        $this->package();

        $data = app(IncomeCertificate::class)->data($this->employee, []);

        // 200k + 20k + 5k + 25k. The 100k bonus is out — it is what one month held — and the 10k advance is
        // out because a deduction is not income the employee does not have.
        $this->assertSame(250000.0, $data['monthly_gross']);
        $this->assertSame(3000000.0, $data['annual_gross']);
    }

    public function test_the_amounts_are_written_out_in_words(): void
    {
        $this->package();

        $data = app(IncomeCertificate::class)->data($this->employee, []);

        $this->assertSame('Two Hundred Fifty Thousand', $data['monthly_gross_words']);
        $this->assertSame('Three Million', $data['annual_gross_words']);
    }

    public function test_it_names_what_is_missing_rather_than_printing_a_hole(): void
    {
        // No package, no CNIC, and a letterhead with no NTN.
        $this->employee->update(['nic' => null]);
        app(TenantSettings::class)->set('company.ntn', null);

        $missing = app(IncomeCertificate::class)->missingFor($this->employee->fresh());

        // Every gap names the screen that fixes it: the first person to read "a salary package on file"
        // asked where that goes, which is a fair question — a package is a row under Employee Settings,
        // not a field on the employee record.
        $this->assertContains('the employee\'s CNIC (Employees → edit this employee)', $missing);
        $this->assertContains('a salary package for this employee (Employee → Employee Settings → New)', $missing);
        $this->assertContains('the company NTN (Company Settings → Letterhead)', $missing);
    }

    public function test_a_complete_record_is_missing_nothing(): void
    {
        $this->package();

        $this->assertSame([], app(IncomeCertificate::class)->missingFor($this->employee->fresh()));
    }

    /** Asking twice gives the same reference, which is what a reference is for. */
    public function test_the_reference_is_deterministic(): void
    {
        $certificates = app(IncomeCertificate::class);

        $this->assertSame(
            $certificates->reference($this->employee),
            $certificates->reference($this->employee),
        );
        $this->assertStringContainsString('EMP-014', $certificates->reference($this->employee));
        $this->assertStringContainsString('/HR/', $certificates->reference($this->employee));
    }

    public function test_the_letter_states_the_facts_it_certifies(): void
    {
        $this->package();

        $html = app(IncomeCertificate::class)
            ->renderPdf($this->employee, [
                'purpose' => 'a visa application',
                'father_name' => 'Abdul Rehman',
                'duties' => 'building and maintaining the company\'s web applications',
            ])
            ->html();

        foreach ([
            'ERBIUMTECH (SMC-Private) Limited',
            'TO WHOM IT MAY CONCERN',
            'Muhammad Hammad',
            '35201-1234567-1',
            'Abdul Rehman',
            'Backend Developer',
            '01 March 2024',
            'PKR 250,000',
            'Two Hundred Fifty Thousand',
            'a visa application',
            'Income Tax Ordinance',
            'Muzafar Ali',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "the letter must state: {$expected}");
        }

        // Last four only: the letter proves the salary arrives through a bank, and a full account number on
        // a document that will be photocopied proves nothing more.
        $this->assertStringContainsString('ending 3334', $html);
        $this->assertStringNotContainsString('0001112223334', $html);

        // The bank's short code rather than its full name — the token a transfer instruction and the salary
        // bank file both use.
        $this->assertStringContainsString('Bank transfer to TST', $html);
        $this->assertStringNotContainsString('Test Bank', $html);

        // The amount in words is set smaller than the figure it repeats.
        $this->assertStringContainsString('<span class="in-words">(Two Hundred Fifty Thousand only)</span>', $html);

        // The website is on the footer bar only, so the header does not spend a line repeating it.
        $this->assertSame(1, substr_count($html, 'www.erbium'), 'the website belongs on the bar, once');

        // And the registered office, likewise: on the bar, and not again in the header.
        $this->assertSame(1, substr_count($html, 'Office 5, Gulberg III, Lahore'), 'the address belongs on the bar, once');

        // The bonus must not appear anywhere on a statement of recurring income.
        $this->assertStringNotContainsString('100,000', $html);
    }

    public function test_an_employee_can_download_their_own_certificate(): void
    {
        $this->package();

        $self = User::find($this->employee->user_id);
        $this->actingAs($self);

        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->assertActionVisible('incomeCertificate')
            ->callAction(TestAction::make('incomeCertificate'), [
                'purpose' => 'opening a bank account',
                'father_name' => 'Abdul Rehman',
            ])
            ->assertHasNoActionErrors();
    }

    /** A purpose is required: a certificate with none is one the reader has to guess at. */
    public function test_the_purpose_is_required(): void
    {
        $this->package();

        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->callAction(TestAction::make('incomeCertificate'), ['purpose' => ''])
            ->assertHasActionErrors(['purpose']);
    }
}
