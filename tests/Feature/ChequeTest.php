<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Cheque;
use App\Modules\Accounting\Models\ChequeBook;
use App\Modules\Accounting\Models\ChequeLayout;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Services\ChequePrinter;
use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Core\Models\Bank;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportRenderers;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Cheque printing — docs/saas-launch-plan.md §8.6.
 *
 * Four claims: the words on the cheque are the number the bank will read, in
 * the lakh-and-crore idiom; a number is never reused across void and reprint;
 * printing a cheque is the release, so the bank file leaves the payment alone;
 * and the statement line that names the number clears it and pays the payment.
 */
class ChequeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private ChequePrinter $printer;

    private CompanyBankAccount $account;

    private Beneficiary $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'cheques@test.local'));
        $this->setCurrentTenant();
        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);

        $this->printer = app(ChequePrinter::class);

        $bank = Bank::firstOrCreate(['bank_short_code' => 'HBL'], ['bank_name' => 'Habib Bank', 'bank_code' => '000002']);
        $this->account = CompanyBankAccount::create(['title' => 'HBL Current', 'account_no' => '0011', 'bank_id' => $bank->id, 'is_active' => true]);
        $this->landlord = Beneficiary::create([
            'name' => 'Karachi Properties',
            'is_active' => true,
            'transaction_type_id' => TransactionType::byCode('miscellaneous')?->id,
        ]);
    }

    private function book(int $first = 1001, int $last = 1003, ?string $prefix = null): ChequeBook
    {
        return ChequeBook::create([
            'company_bank_account_id' => $this->account->id,
            'prefix' => $prefix,
            'first_no' => $first,
            'last_no' => $last,
        ]);
    }

    private function payment(float $amount, string $date = '2026-08-10'): Payment
    {
        return Payment::create([
            'payable_type' => ModuleMap::alias(Beneficiary::class),
            'payable_id' => $this->landlord->id,
            'transaction_type_id' => $this->landlord->transaction_type_id,
            'company_bank_account_id' => $this->account->id,
            'amount' => $amount,
            'value_date' => $date,
            'details' => 'August rent',
            'status' => Payment::STATUS_DRAFT,
        ]);
    }

    // ───────────────────────────────────────────────────────────── words ──

    public function test_amounts_are_written_in_the_lakh_and_crore_idiom(): void
    {
        $this->assertSame('Rupees One Only', AmountWords::write(1));
        $this->assertSame('Rupees Nineteen Only', AmountWords::write(19));
        $this->assertSame('Rupees Twenty One Only', AmountWords::write(21));
        $this->assertSame('Rupees One Hundred Only', AmountWords::write(100));
        $this->assertSame('Rupees One Thousand Five Only', AmountWords::write(1005));
        $this->assertSame('Rupees One Lakh Only', AmountWords::write(100000));
        $this->assertSame('Rupees Twelve Lakh Fifty Thousand Only', AmountWords::write(1250000));
        $this->assertSame('Rupees One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Only', AmountWords::write(12345678));
        $this->assertSame('Rupees Fifty Thousand and Fifty Paisa Only', AmountWords::write(50000.5));
        $this->assertSame('Rupees Zero Only', AmountWords::write(0));
    }

    public function test_every_amount_writes_as_one_clean_line(): void
    {
        foreach ([7, 99, 100, 101, 999, 1000, 1010, 99999, 100000, 100001, 1250000, 9999999, 10000000, 10000001, 123456789] as $n) {
            $words = AmountWords::write($n);

            $this->assertMatchesRegularExpression('/^Rupees (?:[A-Z][a-z]+ )+Only$/', $words, "words for {$n}");
            $this->assertStringNotContainsString('  ', $words, "double space in words for {$n}");
        }
    }

    // ───────────────────────────────────────────────────────── numbering ──

    public function test_printing_takes_the_next_number_and_releases_the_payment(): void
    {
        $book = $this->book(prefix: 'A');
        $payment = $this->payment(125000);

        $cheque = $this->printer->print($payment);

        $this->assertSame('A1001', $cheque->cheque_no);
        $this->assertSame('Karachi Properties', $cheque->payee_name);
        $this->assertSame(Cheque::STATUS_PRINTED, $cheque->status);
        $this->assertSame(1002, $book->fresh()->next_no);

        $payment->refresh();
        $this->assertSame(Payment::STATUS_EXPORTED, $payment->status);
        $this->assertSame('cheque', $payment->payment_type);
        $this->assertSame('CHQ A1001', $payment->batch_reference);
        $this->assertNotNull($payment->journal_entry_id, 'a draft is approved and booked on the way to the printer');
    }

    public function test_a_number_is_never_reused_across_void_and_reprint(): void
    {
        $this->book();
        $payment = $this->payment(5000);

        $first = $this->printer->print($payment);
        $this->assertSame(Payment::STATUS_EXPORTED, $payment->fresh()->status);

        $second = $this->printer->reprint($first, 'Printer jammed');

        $this->assertSame('1002', $second->cheque_no);
        $this->assertSame(Cheque::STATUS_VOID, $first->fresh()->status);
        $this->assertSame('Printer jammed', $first->fresh()->void_reason);
        $this->assertSame(Payment::STATUS_EXPORTED, $payment->fresh()->status);
        $this->assertSame('CHQ 1002', $payment->fresh()->batch_reference);

        $this->printer->void($second, 'Lost in the post');
        $this->assertSame(Payment::STATUS_APPROVED, $payment->fresh()->status, 'a voided cheque puts the payment back in the pool');

        $third = $this->printer->print($payment->fresh());
        $this->assertSame('1003', $third->cheque_no);
        $this->assertSame(['1001', '1002', '1003'], Cheque::query()->orderBy('id')->pluck('cheque_no')->all());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No cheque book with numbers left');
        $this->printer->print($this->payment(1));
    }

    public function test_a_second_cheque_for_a_payment_with_one_out_is_refused(): void
    {
        $this->book();
        $payment = $this->payment(5000);
        $this->printer->print($payment);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has a cheque out');
        $this->printer->print($payment->fresh());
    }

    // ───────────────────────────────────────────────────────── bank file ──

    public function test_the_bank_file_leaves_cheque_payments_alone(): void
    {
        $this->book();
        $byCheque = $this->payment(5000);
        $byTransfer = $this->payment(7000);
        $this->printer->print($byCheque);
        $this->printer->void($byCheque->fresh()->cheques()->first(), 'Spoiled');

        $page = app(BankPaymentFile::class);
        $page->data = ['month' => null, 'type' => null];
        $ids = $page->getRows()->pluck('id')->all();

        $this->assertContains($byTransfer->id, $ids);
        $this->assertNotContains($byCheque->id, $ids, 'a voided cheque payment is back in the pool but still pays by cheque, not by file');
    }

    // ────────────────────────────────────────────────────── reconciliation ──

    public function test_a_statement_line_naming_the_cheque_clears_it_and_pays_the_payment(): void
    {
        $this->book();
        $payment = $this->payment(5000, '2026-08-10');
        $cheque = $this->printer->print($payment);

        $service = app(BankReconciliationService::class);
        $statement = BankStatement::create([
            'account_id' => Account::where('code', '1100')->firstOrFail()->id,
            'statement_date' => '2026-09-30',
            'opening_balance' => 0,
            'closing_balance' => -5000,
        ]);
        // Cleared six weeks after it was written: outside the date window, found by the number.
        $service->import([
            ['transaction_date' => '2026-09-22', 'description' => 'CLEARING CHQ 1001', 'reference' => null, 'amount' => -5000],
        ], $statement);

        $this->assertSame(1, $service->autoMatch($statement));
        $this->assertSame(Cheque::STATUS_CLEARED, $cheque->fresh()->status);
        $this->assertNotNull($cheque->fresh()->cleared_at);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    // ──────────────────────────────────────────────────── layout and PDF ──

    public function test_the_layout_falls_back_from_account_to_default_to_shipped(): void
    {
        $this->assertSame('Shipped default', ChequeLayout::for($this->account->id)->name);

        ChequeLayout::create(['name' => 'House default', 'page_width_mm' => 180, 'page_height_mm' => 80, 'fields' => ['payee' => ['x' => 30]]]);
        $this->assertSame('House default', ChequeLayout::for($this->account->id)->name);
        $this->assertSame(30.0, (float) ChequeLayout::for($this->account->id)->field('payee')['x']);
        $this->assertSame(26.0, (float) ChequeLayout::for($this->account->id)->field('payee')['y'], 'an unset coordinate keeps the shipped value');

        ChequeLayout::create(['company_bank_account_id' => $this->account->id, 'name' => 'HBL leaf', 'page_width_mm' => 175, 'page_height_mm' => 78, 'fields' => []]);
        $this->assertSame('HBL leaf', ChequeLayout::for($this->account->id)->name);
    }

    public function test_the_cheque_html_carries_the_words_and_the_positions(): void
    {
        $this->book();
        $cheque = $this->printer->print($this->payment(1250000.5));

        $html = $this->printer->renderPdf($cheque)->html();

        $this->assertStringContainsString('Rupees Twelve Lakh Fifty Thousand and Fifty Paisa Only', $html);
        $this->assertStringContainsString('Karachi Properties', $html);
        $this->assertStringContainsString('A/C PAYEE ONLY', $html);
        $this->assertStringContainsString('size: 180mm 80mm', $html);
        $this->assertStringContainsString('left: 24mm; top: 26mm', $html);
    }

    public function test_the_register_totals_unpresented_and_cleared_and_lists_void_without_an_amount(): void
    {
        $this->book();
        $out = $this->printer->print($this->payment(5000));
        $spoiled = $this->printer->print($this->payment(900));
        $this->printer->void($spoiled, 'Spoiled');
        $cleared = $this->printer->print($this->payment(1200));
        $this->printer->clear($cleared);

        $report = ReportRenderers::render('ChequeRegister', '2026-12-31', false, []);

        $this->assertSame(5000.0, $report['tiles'][0]['value']);
        $this->assertSame(1200.0, $report['tiles'][1]['value']);
        $this->assertCount(3, $report['rows']);
        $this->assertSame('', collect($report['rows'])->firstWhere(0, $spoiled->cheque_no)[4]);
        $this->assertStringContainsString('Void — Spoiled', collect($report['rows'])->firstWhere(0, $spoiled->cheque_no)[5]);
        $this->assertSame('3 PRINTED · 1 OUTSTANDING · 1 VOID', $report['note']);
    }
}
