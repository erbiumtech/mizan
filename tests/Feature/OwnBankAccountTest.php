<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Support\BankFileAccount;
use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Payroll\Models\Payslip;
use Database\Seeders\TransactionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An employee who banks with us, which the bank file treats differently.
 *
 * A transfer inside our own bank is intra-bank and keyed on the plain account number; every
 * other bank is an IBFT keyed on the IBAN. Send one where the other is expected and the
 * payment is rejected or — worse — misdirected.
 *
 * Two things went wrong together in the case this comes from. The employee record could not
 * express it: `bank_id` was required, and our own bank is deliberately absent from the
 * directory, which lists the banks we transfer *out* to. And the export said nothing: it
 * fell back to the IBAN, so the row looked complete, the file looked valid, and 47,000.00
 * went out on an identifier the bank would not accept.
 */
class OwnBankAccountTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** Characters 5-8 are the bank: SCBL is ours. */
    private const OWN_BANK_IBAN = 'PK36SCBL0000001123456702';

    private const OTHER_BANK_IBAN = 'PK63FAYS3267383000003963';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);

        $this->actingAs($this->makeUser('Administrator', 'ownbank@test.local'));
        $this->setCurrentTenant();
    }

    /**
     * Complete enough to save through the form, which requires NIC and its two scans — none
     * of which this test is about, and all of which would otherwise fail the save for
     * reasons unrelated to bank details.
     */
    private function employee(string $email, array $bank = []): Employee
    {
        Storage::fake('public');
        Storage::disk('public')->put('nic/front.jpg', 'front');
        Storage::disk('public')->put('nic/back.jpg', 'back');

        return Employee::create($bank + [
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => 'EMP-'.strtoupper(substr(md5($email), 0, 5)),
            'designation' => 'Backend Developer',
            'department' => 'IT',
            'gender' => 'Male',
            'phone' => '0300-'.random_int(1000000, 9999999),
            'secondary_phone' => '0301-'.random_int(1000000, 9999999),
            'nic' => '12345-'.random_int(1000000, 9999999).'-1',
            'nic_front' => 'nic/front.jpg',
            'nic_back' => 'nic/back.jpg',
        ]);
    }

    private function acceptedPayslip(Employee $employee): Payslip
    {
        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'basic_wage' => 50000,
            'net_salary' => 47000,
        ]);

        DB::table('payslips')->where('id', $payslip->id)
            ->update(['employee_review' => Payslip::REVIEW_ACCEPTED]);

        return $payslip->fresh();
    }

    private function salaryFile(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(SalaryBankFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July']);
    }

    // ---- Which identifier the file uses -------------------------------------

    public function test_our_own_bank_is_recognised_from_the_iban(): void
    {
        // Not from the directory, which does not list us, and not from a flag somebody has
        // to remember to set.
        $this->assertTrue(BankFileAccount::isOwnBank(self::OWN_BANK_IBAN, null, null));
        $this->assertFalse(BankFileAccount::isOwnBank(self::OTHER_BANK_IBAN, null, null));
    }

    public function test_an_own_bank_employee_is_paid_on_the_account_number(): void
    {
        $account = BankFileAccount::resolve(self::OWN_BANK_IBAN, '0000001123456702', null, 'SCB');

        $this->assertSame('0000001123456702', $account['value']);
        $this->assertSame('account_no', $account['kind']);
        $this->assertNull($account['problem']);
    }

    public function test_another_banks_employee_is_paid_on_the_iban(): void
    {
        $account = BankFileAccount::resolve(self::OTHER_BANK_IBAN, '0102030405', null, 'FBL');

        $this->assertSame(self::OTHER_BANK_IBAN, $account['value']);
        $this->assertSame('iban', $account['kind']);
        $this->assertNull($account['problem'], 'an IBAN is exactly right for a bank we transfer out to');
    }

    public function test_an_own_bank_employee_with_only_an_iban_is_a_problem(): void
    {
        $account = BankFileAccount::resolve(self::OWN_BANK_IBAN, null, null, 'SCB');

        $this->assertSame(BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY, $account['problem']);
        $this->assertSame(
            self::OWN_BANK_IBAN,
            $account['value'],
            'still returned, so a caller that only wants an identifier gets the best one there is',
        );
    }

    public function test_no_account_at_all_is_a_different_problem(): void
    {
        $this->assertSame(
            BankFileAccount::PROBLEM_NO_ACCOUNT,
            BankFileAccount::resolve(null, null)['problem'],
        );
    }

    // ---- The export refuses the wrong kind ----------------------------------

    public function test_an_own_bank_row_with_only_an_iban_is_held_back(): void
    {
        // The case this exists for: the row looks complete, the file looks valid, and the
        // payment fails at the bank days later.
        $employee = $this->employee('ownbank-iban@test.local', ['iban_no' => self::OWN_BANK_IBAN]);
        $this->acceptedPayslip($employee);

        $this->assertCount(0, $this->salaryFile()->instance()->getReleasablePayments());
    }

    public function test_it_says_what_is_wrong_and_where_to_fix_it(): void
    {
        $employee = $this->employee('ownbank-why@test.local', ['iban_no' => self::OWN_BANK_IBAN]);
        $this->acceptedPayslip($employee);

        $this->salaryFile()
            ->assertSee('Wrong kind of account number on file')
            ->assertSee('bank with us and have only an IBAN on file');
    }

    public function test_adding_the_account_number_releases_it(): void
    {
        $employee = $this->employee('ownbank-fixed@test.local', ['iban_no' => self::OWN_BANK_IBAN]);
        $this->acceptedPayslip($employee);

        $employee->update(['bank_account_no' => '0000001123456702']);

        $releasable = $this->salaryFile()->instance()->getReleasablePayments();

        $this->assertCount(1, $releasable);
        $this->assertSame('0000001123456702', $releasable[0]['account']);
    }

    public function test_an_other_bank_employee_with_only_an_iban_is_untouched(): void
    {
        // The common case, and the one that must not be made stricter: an IBAN is the right
        // identifier for a bank we transfer out to.
        $employee = $this->employee('otherbank@test.local', ['iban_no' => self::OTHER_BANK_IBAN]);
        $this->acceptedPayslip($employee);

        $this->assertCount(1, $this->salaryFile()->instance()->getReleasablePayments());
    }

    /**
     * A blank account is still exported, with a warning, which is the decision the code
     * already made — "a blank field is never better than the wrong-shaped identifier". It is
     * also visible, unlike the case above, which is why only one of the two is refused.
     */
    public function test_a_missing_account_is_warned_about_rather_than_held(): void
    {
        $employee = $this->employee('noaccount@test.local');
        $this->acceptedPayslip($employee);

        $this->assertCount(1, $this->salaryFile()->instance()->getReleasablePayments());

        $this->salaryFile()->assertSee('have no bank account or IBAN on file');
    }

    public function test_the_payment_itself_refuses_to_be_released(): void
    {
        // On the Payment rather than in the page, so the general bank-file page and the
        // release service inherit it — a file built anywhere would have sent it.
        $employee = $this->employee('ownbank-payment@test.local', ['iban_no' => self::OWN_BANK_IBAN]);
        $payslip = $this->acceptedPayslip($employee);

        $this->salaryFile()->instance()->getPayments();

        $payment = Payment::where('payslip_id', $payslip->id)->firstOrFail();

        $this->assertFalse($payment->isReleasable());
        $this->assertSame(BankFileAccount::PROBLEM_OWN_BANK_IBAN_ONLY, $payment->releaseBlockedCategory());
        $this->assertStringContainsString('account number', $payment->releaseBlockedReason());
    }

    // ---- The employee record can express it ---------------------------------

    public function test_an_own_bank_employee_can_be_saved_without_a_bank_from_the_directory(): void
    {
        // Our own bank is not in the directory — it lists the banks we transfer out to — so
        // requiring one made this employee unsaveable.
        $employee = $this->employee('ownbank-form@test.local', ['iban_no' => self::OWN_BANK_IBAN]);

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->fillForm([
                'bank_id' => null,
                'bank_short_code' => 'SCB',
                'bank_account_no' => '0000001123456702',
                'iban_no' => self::OWN_BANK_IBAN,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee->refresh();

        $this->assertNull($employee->bank_id);
        $this->assertSame('SCB', $employee->bank_short_code);
        $this->assertSame('0000001123456702', $employee->bank_account_no);
    }

    public function test_a_short_code_is_required_when_no_bank_is_chosen(): void
    {
        // Otherwise the beneficiary bank column of the file is blank and nothing said so.
        $employee = $this->employee('ownbank-nocode@test.local', ['iban_no' => self::OWN_BANK_IBAN]);

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->fillForm([
                'bank_id' => null,
                'bank_short_code' => null,
                'bank_account_no' => '0000001123456702',
            ])
            ->call('save')
            ->assertHasFormErrors(['bank_short_code']);
    }

    public function test_one_identifier_is_enough(): void
    {
        // Requiring both blocked an employee whose other identifier nobody has. Which one is
        // needed depends on the bank, and the file preview refuses the row if it is wrong.
        $employee = $this->employee('one-id@test.local');

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->fillForm([
                'bank_id' => null,
                'bank_short_code' => 'SCB',
                'bank_account_no' => '0000001123456702',
                'iban_no' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0000001123456702', $employee->refresh()->bank_account_no);
    }

    /**
     * The other half of why this case could not be recorded: even with the field filled in,
     * the model blanked the copy on every save where no directory bank was chosen — and an
     * own-bank employee never has one.
     */
    public function test_a_short_code_survives_a_save_with_no_bank_chosen(): void
    {
        $employee = $this->employee('ownbank-survives@test.local', [
            'bank_short_code' => 'SCB',
            'bank_account_no' => '0000001123456702',
        ]);

        $employee->update(['designation' => 'Senior Backend Developer']);

        $this->assertSame('SCB', $employee->refresh()->bank_short_code);
    }

    public function test_removing_a_bank_still_clears_the_code_it_put_there(): void
    {
        // The behaviour the blanking existed for, which has to keep working: those columns
        // described the bank that was just removed.
        $bank = \App\Modules\Accounting\Models\Bank::create([
            'bank_code' => 'FAYS', 'bank_name' => 'Faysal Bank', 'bank_short_code' => 'FBL',
        ]);

        $employee = $this->employee('had-a-bank@test.local', ['bank_id' => $bank->id]);

        $this->assertSame('FBL', $employee->refresh()->bank_short_code, 'copied from the bank');

        $employee->update(['bank_id' => null]);

        $this->assertNull($employee->refresh()->bank_short_code);
    }

    public function test_neither_identifier_is_refused(): void
    {
        $employee = $this->employee('no-id@test.local');

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->fillForm([
                'bank_id' => null,
                'bank_short_code' => 'SCB',
                'bank_account_no' => null,
                'iban_no' => null,
            ])
            ->call('save')
            ->assertHasFormErrors(['bank_account_no', 'iban_no']);
    }
}
