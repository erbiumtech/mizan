<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\BankFileFormat;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\BankFileRenderer;
use App\Modules\Accounting\Services\BankPaymentExportService;
use App\Modules\Core\Models\Bank;
use App\Support\ModuleMap;
use Database\Seeders\BankFileFormatSeeder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The bank-file designer — docs/saas-launch-plan.md §14.1. The claims: the
 * shipped iPayments writer is what a company gets until it designs one; a
 * designed format renders header, rows and trailer over placeholders and
 * nothing else; an account's own format outranks the default; and a designed
 * format is previewed against the real rows before it can be downloaded.
 */
class BankFileFormatTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private CompanyBankAccount $account;

    private Beneficiary $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->actingAs($this->makeUser('Administrator', 'formats@test.local'));
        $this->setCurrentTenant();
        $this->seed([\Database\Seeders\TransactionTypeSeeder::class, BankFileFormatSeeder::class]);

        $hbl = Bank::firstOrCreate(['bank_short_code' => 'HBL'], ['bank_name' => 'Habib Bank', 'bank_code' => '600648']);
        $this->account = CompanyBankAccount::create(['title' => 'HBL Current', 'account_no' => '0011', 'bank_id' => $hbl->id, 'is_active' => true]);
        $this->landlord = Beneficiary::create(['name' => 'Karachi Properties', 'bank_id' => $hbl->id, 'iban' => 'PK24HABB0000001123456702', 'account_no' => '5544332211', 'payment_type' => 'IBFT', 'is_active' => true]);
    }

    private function payment(float $amount, string $details = 'Rent'): Payment
    {
        return Payment::create([
            'payable_type' => ModuleMap::alias(Beneficiary::class), 'payable_id' => $this->landlord->id,
            'transaction_type_id' => TransactionType::byCode('miscellaneous')?->id ?? TransactionType::query()->value('id'),
            'company_bank_account_id' => $this->account->id, 'amount' => $amount, 'details' => $details,
            'value_date' => '2026-11-10', 'status' => Payment::STATUS_APPROVED,
        ]);
    }

    private function designed(array $attributes = []): BankFileFormat
    {
        return BankFileFormat::create(array_merge([
            'name' => 'HBL bulk', 'kind' => BankFileFormat::KIND_TEMPLATE, 'company_bank_account_id' => $this->account->id,
            'header_template' => 'H,{count},{total:0},{value_date:Ymd},{debit_account}',
            'row_template' => '{row_no},{beneficiary_name:10},{beneficiary_iban},{beneficiary_bank_code},{amount},{details},{value_date:d/m/Y},{nonsense}',
            'trailer_template' => 'T,{count},{total}',
            'line_ending' => 'lf',
            'extension' => 'txt',
            'previewed_at' => now(),
        ], $attributes));
    }

    public function test_the_shipped_writer_is_the_format_until_one_is_designed(): void
    {
        $this->assertSame('Standard Chartered iPayments', BankFileFormat::forAccount($this->account->id)->name);
        $this->assertFalse(BankFileFormat::forAccount($this->account->id)->isTemplate());

        $csv = app(BankPaymentExportService::class)->exportPayments(collect([$this->payment(25000)]));
        $this->assertStringStartsWith('H,P,', $csv, 'the iPayments header');
        $this->assertSame(204, count(explode(',', explode("\r\n", $csv)[1])), 'the 204-column row');
    }

    public function test_a_designed_format_renders_the_three_lines_over_placeholders(): void
    {
        $this->designed();
        $this->payment(25000.5, 'November rent');
        $this->payment(1000);

        $file = app(BankPaymentExportService::class)->exportPayments(Payment::query()->orderBy('id')->get());
        $lines = explode("\n", rtrim($file, "\n"));

        $this->assertSame('H,2,26001,20261105,0011', $lines[0], 'the header carries the file\'s value date — today, none given — and the rows their own');
        $this->assertSame('1,Karachi Pr,PK24HABB0000001123456702,600648,25000.50,November rent,10/11/2026,', $lines[1], 'a width cuts, an unknown placeholder is empty');
        $this->assertSame('2,Karachi Pr,PK24HABB0000001123456702,600648,1000.00,Rent,10/11/2026,', $lines[2]);
        $this->assertSame('T,2,26000.50', $lines[3]);
        $this->assertStringNotContainsString("\r", $file, 'LF as designed');
        $this->assertStringEndsWith('.txt', app(BankPaymentExportService::class)->paymentFileName(null, $this->account->id));
    }

    public function test_an_accounts_own_format_outranks_the_default(): void
    {
        $default = BankFileFormat::create(['name' => 'House default', 'kind' => BankFileFormat::KIND_TEMPLATE, 'row_template' => 'D,{beneficiary_name}', 'previewed_at' => now()]);
        $this->assertSame('House default', BankFileFormat::forAccount($this->account->id)->name);

        $own = $this->designed();
        $this->assertSame('HBL bulk', BankFileFormat::forAccount($this->account->id)->name);
        $this->assertSame('House default', BankFileFormat::forAccount(null)->name);

        $own->update(['is_active' => false]);
        $this->assertSame('House default', BankFileFormat::forAccount($this->account->id)->name);
        $default->update(['is_active' => false]);
        $this->assertFalse(BankFileFormat::forAccount($this->account->id)->isTemplate(), 'back to the shipped writer');
    }

    public function test_a_designed_format_is_previewed_before_it_is_downloaded(): void
    {
        $format = $this->designed(['previewed_at' => null]);
        $this->payment(100);

        try {
            app(BankPaymentExportService::class)->exportPayments(Payment::all());
            $this->fail('A never-previewed format produced a bank file.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preview', $e->getMessage());
        }

        // The preview itself renders — and is what marks the format previewed.
        $text = app(BankPaymentExportService::class)->renderWith($format, Payment::all(), null, preview: true);
        $this->assertStringContainsString('T,1,100.00', $text);
        $format->forceFill(['previewed_at' => now()])->save();
        $this->assertNotEmpty(app(BankPaymentExportService::class)->exportPayments(Payment::all()));

        // A change after the preview closes the download again.
        Carbon::setTestNow('2026-11-05 11:00:00');
        $format->update(['row_template' => '{beneficiary_name}']);
        $this->assertTrue($format->fresh()->needsPreview());
        $this->expectException(InvalidArgumentException::class);
        app(BankPaymentExportService::class)->exportPayments(Payment::all());
    }

    public function test_amount_and_date_modifiers(): void
    {
        $renderer = app(BankFileRenderer::class);
        $values = ['amount' => 1234.5, 'value_date' => '2026-11-10', 'beneficiary_name' => 'A very long beneficiary name'];

        $this->assertSame('1234.50|1235|123450|10/11/2026|20261110|A very', $renderer->fill('{amount}|{amount:0}|{amount:cents}|{value_date}|{value_date:Ymd}|{beneficiary_name:6}', $values));
    }
}
