<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\Pages\CreateCompanyBankAccount;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\BillPaymentService;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\ModuleMap;
use Database\Seeders\TransactionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A company bank account names the ledger account its money is booked to.
 *
 * Every payment used to credit 1100 Cash/Bank whichever bank it was drawn on, so two banks shared one
 * ledger balance and neither statement could be reconciled against it. The account is a field on the bank
 * account now; existing rows were backfilled with 1100, which is what their payments always credited.
 */
class CompanyBankAccountLedgerTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Account $hbl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'banks@test.local'));
        $this->setCurrentTenant();

        $this->seed(TransactionTypeSeeder::class);

        $this->hbl = Account::create([
            'code' => '1120',
            'name' => 'HBL current',
            'type' => 'asset',
            'parent_id' => Account::where('code', '1000')->value('id'),
            'is_active' => true,
            'allow_manual_entry' => true,
        ]);
    }

    public function test_a_payment_credits_the_ledger_account_of_the_bank_it_is_drawn_on(): void
    {
        $bank = $this->bankAccount($this->hbl->id);

        $entry = $this->approvedPayment($bank)->journalEntry;

        $this->assertSame(25_000.0, (float) $entry->lines()->where('account_id', $this->hbl->id)->sum('credit_amount'));
        $this->assertSame(0.0, (float) $entry->lines()->where('account_id', $this->accountId('1100'))->sum('credit_amount'));
    }

    /** A bank account nobody has mapped, or a payment drawn on none, books as every payment always did. */
    public function test_without_a_ledger_account_a_payment_credits_cash_bank(): void
    {
        foreach ([$this->bankAccount(null), null] as $bank) {
            $entry = $this->approvedPayment($bank)->journalEntry;

            $this->assertSame(25_000.0, (float) $entry->lines()->where('account_id', $this->accountId('1100'))->sum('credit_amount'));
        }
    }

    public function test_a_bill_paid_through_the_bank_credits_that_bank(): void
    {
        $bank = $this->bankAccount($this->hbl->id);
        $bill = $this->bill();

        $payment = app(BillPaymentService::class)->raise($bill, null, $bank->id);
        app(PaymentService::class)->approve($payment);

        $entry = $payment->fresh()->journalEntry;
        $this->assertSame(Invoice::STATUS_PAID, $bill->fresh()->status);
        $this->assertSame(50_000.0, (float) $entry->lines()->where('account_id', $this->hbl->id)->sum('credit_amount'));
        $this->assertSame(0.0, (float) $entry->lines()->where('account_id', $this->accountId('1100'))->sum('credit_amount'));
    }

    public function test_existing_bank_accounts_are_backfilled_with_cash_bank(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_30_230000_add_account_id_to_company_bank_accounts_table.php');
        $migration->down();

        DB::table('company_bank_accounts')->insert(['title' => 'Old account', 'account_no' => '1', 'is_active' => true]);

        $migration->up();

        $this->assertSame($this->accountId('1100'), (int) DB::table('company_bank_accounts')->where('title', 'Old account')->value('account_id'));
    }

    public function test_the_form_asks_for_the_ledger_account(): void
    {
        Livewire::test(CreateCompanyBankAccount::class)
            ->fillForm(['title' => 'No ledger', 'account_no' => '9'])
            ->call('create')
            ->assertHasFormErrors(['account_id' => 'required']);

        Livewire::test(CreateCompanyBankAccount::class)
            ->fillForm(['title' => 'HBL', 'account_no' => '9', 'account_id' => $this->hbl->id, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->hbl->id, CompanyBankAccount::where('title', 'HBL')->value('account_id'));
    }

    private function bankAccount(?int $accountId): CompanyBankAccount
    {
        return CompanyBankAccount::create([
            'title' => 'Bank '.uniqid(),
            'account_no' => '0101-'.random_int(1000, 9999),
            'account_id' => $accountId,
            'is_active' => true,
        ]);
    }

    private function approvedPayment(?CompanyBankAccount $bank): Payment
    {
        $payee = Beneficiary::create(['name' => 'Landlord', 'is_active' => true]);

        $payment = Payment::create([
            'payable_type' => ModuleMap::alias(Beneficiary::class),
            'payable_id' => $payee->id,
            'transaction_type_id' => TransactionType::byCode('rent')?->id,
            'company_bank_account_id' => $bank?->id,
            'amount' => 25_000,
            'value_date' => '2026-07-15',
            'details' => 'Rent for July',
            'status' => Payment::STATUS_DRAFT,
        ]);

        return app(PaymentService::class)->approve($payment)->fresh();
    }

    private function bill(): Invoice
    {
        $supplier = Contact::create(['name' => 'Lahore Looms', 'kind' => Contact::KIND_SUPPLIER, 'iban' => 'PK36MEZN0000001123456702']);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $supplier->id,
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);

        $invoice->lines()->create([
            'description' => 'Office rent, October',
            'quantity' => 1,
            'unit_price' => 50_000,
            'line_total' => 50_000,
            'account_id' => $this->accountId('5700'),
        ]);

        $invoice->forceFill(['subtotal' => 50_000, 'tax_amount' => 0, 'total' => 50_000])->save();

        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }

    private function accountId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }
}
