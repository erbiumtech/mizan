<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Models\WithholdingDeduction;
use App\Modules\Accounting\Models\WithholdingSection;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Accounting\Services\WithholdingService;
use App\Modules\Accounting\Support\ReportPane;
use App\Modules\Accounting\Support\WithholdingReports;
use App\Modules\Core\Models\CompanyModule;
use Database\Seeders\WithholdingSectionSeeder;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Tax withheld at source from suppliers — `docs/erpnext-gap-plan.md` Phase 4.
 *
 * The application withheld §149 from salaries and nothing from anybody else, so a company paying contractors
 * had to type the §153 deduction as a journal line, remember it every month, and reconstruct a year of them
 * for the §165 statement. The plan's item is "one deduction line on the payment's journal entry at
 * approval", and these tests are mostly about the two halves of that sentence: the *line*, and *at approval*.
 *
 * Three of them are the ones worth reading:
 *
 *  - **`test_a_payment_without_a_section_is_posted_exactly_as_before`** — the phase's own constraint. Every
 *    beneficiary that exists today has no section, so every payment behaves as it did.
 *  - **`test_the_bank_file_pays_the_net`** — the consequence somebody would otherwise discover from a bank
 *    statement. The company owes the gross and transfers the gross less the tax it keeps back.
 *  - **`test_an_annual_threshold_is_crossed_by_the_year_and_not_by_the_payment`** — the thresholds are most
 *    of what §153 is, and the annual one cannot be answered by looking at the payment in front of you.
 */
class WithholdingTaxTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-09-15';

    private TransactionType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AS_OF.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'withholding@test.local'));
        $this->setCurrentTenant();

        foreach (['accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();

        $this->type = TransactionType::create([
            'name' => 'Consultancy',
            'code' => 'consultancy',
            'account_id' => Account::where('code', '5800')->orWhere('code', '5700')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────── the deduction ──

    /**
     * The phase's constraint, first: nothing that works today works differently.
     *
     * Every beneficiary in every existing company has no withholding section, so this is the case that
     * covers all of them — two lines, cash credited the whole amount, and no deduction row anywhere.
     */
    public function test_a_payment_without_a_section_is_posted_exactly_as_before(): void
    {
        $payment = $this->payment($this->beneficiary(), 100_000);

        $entry = app(PaymentService::class)->approve($payment)->journalEntry;

        $this->assertCount(2, $entry->lines);
        $this->assertSame(100_000.0, (float) $this->creditTo($entry, '1100'));
        $this->assertSame(0, WithholdingDeduction::count());
    }

    /** With a section assigned, the same payment posts three lines and the bank is credited the net. */
    public function test_a_deduction_becomes_the_third_line_of_the_payment_entry(): void
    {
        $section = $this->section(rateNonFiler: 20);
        $payment = $this->payment($this->beneficiary($section), 100_000);

        $entry = app(PaymentService::class)->approve($payment)->journalEntry;

        $this->assertCount(3, $entry->lines);
        // The obligation is unchanged: what the supplier earned is still the expense.
        $this->assertSame(100_000.0, (float) $this->debitTo($entry, '5700', '5800'));
        $this->assertSame(80_000.0, (float) $this->creditTo($entry, '1100'));
        $this->assertSame(20_000.0, (float) $this->creditTo($entry, '2100'));
        $this->assertTrue($entry->is_posted);
    }

    /** And the deduction is written down, with the two figures the statement needs. */
    public function test_the_deduction_records_the_gross_and_the_rate_it_applied(): void
    {
        $section = $this->section(rateNonFiler: 11);
        $payment = $this->payment($this->beneficiary($section), 50_000);

        app(PaymentService::class)->approve($payment);

        $deduction = WithholdingDeduction::firstOrFail();

        $this->assertSame($payment->getKey(), $deduction->payment_id);
        $this->assertSame(50_000.0, (float) $deduction->taxable_amount);
        $this->assertSame(11.0, (float) $deduction->rate);
        $this->assertSame(5_500.0, (float) $deduction->amount);
        $this->assertFalse($deduction->was_filer);
        $this->assertSame(self::AS_OF, $deduction->deducted_on->toDateString());
        $this->assertNotNull($deduction->journal_entry_id);
    }

    /** A filer pays the lower rate, which is the whole reason the section carries two of them. */
    public function test_a_filer_is_withheld_from_at_the_filer_rate(): void
    {
        $section = $this->section(rateFiler: 8, rateNonFiler: 16);

        $filer = $this->beneficiary($section, ['name' => 'Files', 'is_filer' => true]);
        $nonFiler = $this->beneficiary($section, ['name' => 'Does not file', 'is_filer' => false]);

        app(PaymentService::class)->approve($this->payment($filer, 10_000));
        app(PaymentService::class)->approve($this->payment($nonFiler, 10_000));

        $this->assertSame(800.0, (float) WithholdingDeduction::where('beneficiary_id', $filer->getKey())->value('amount'));
        $this->assertSame(1_600.0, (float) WithholdingDeduction::where('beneficiary_id', $nonFiler->getKey())->value('amount'));
    }

    /**
     * A lapsed section withholds nothing.
     *
     * The rate that applied last September has to stay the rate that applied last September, so a Finance
     * Act change is a new row and the old one is closed — and a closed row must not go on deducting.
     */
    public function test_a_section_that_has_lapsed_does_not_deduct(): void
    {
        $section = $this->section(rateNonFiler: 11);
        $section->update(['effective_to' => '2026-06-30']);

        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 100_000));

        $this->assertSame(0, WithholdingDeduction::count());
    }

    /** As does one switched off, which is how a company says it does not buy this kind of thing. */
    public function test_an_inactive_section_does_not_deduct(): void
    {
        $section = $this->section(rateNonFiler: 11);
        $section->update(['is_active' => false]);

        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 100_000));

        $this->assertSame(0, WithholdingDeduction::count());
    }

    // ─────────────────────────────────────────────────── the thresholds ──

    /** Under the per-payment limit, nothing is withheld. */
    public function test_a_payment_below_the_per_payment_threshold_is_not_withheld_from(): void
    {
        $section = $this->section(rateNonFiler: 11, perPayment: 25_000);

        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 20_000));

        $this->assertSame(0, WithholdingDeduction::count());
    }

    /** At it, the whole payment is. */
    public function test_a_payment_at_the_per_payment_threshold_is_withheld_from_in_full(): void
    {
        $section = $this->section(rateNonFiler: 11, perPayment: 25_000);

        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 25_000));

        $this->assertSame(2_750.0, (float) WithholdingDeduction::firstOrFail()->amount);
    }

    /**
     * The annual limit is the one that cannot be answered from the payment in front of you.
     *
     * Three payments of 12,000 against a 30,000 annual limit: the first two are under it and the third
     * crosses it. The third is withheld from in full — 1,320 rather than 660 on the 6,000 of excess —
     * because the earlier payments are settled and posted, and revisiting them would mean a tax line on
     * money somebody has already banked.
     */
    public function test_an_annual_threshold_is_crossed_by_the_year_and_not_by_the_payment(): void
    {
        $section = $this->section(rateNonFiler: 11, annual: 30_000);
        $beneficiary = $this->beneficiary($section);

        foreach (['2026-07-10', '2026-08-10', '2026-09-10'] as $date) {
            app(PaymentService::class)->approve($this->payment($beneficiary, 12_000, $date));
        }

        $deductions = WithholdingDeduction::orderBy('deducted_on')->get();

        $this->assertCount(1, $deductions);
        $this->assertSame('2026-09-10', $deductions->first()->deducted_on->toDateString());
        $this->assertSame(1_320.0, (float) $deductions->first()->amount);
    }

    /**
     * And it is counted per supplier, not per company.
     *
     * The obvious way to get this wrong is to sum the section's payments, which would start withholding from
     * a small supplier because a different one crossed the limit.
     */
    public function test_the_annual_total_is_that_suppliers_own(): void
    {
        $section = $this->section(rateNonFiler: 11, annual: 30_000);
        $busy = $this->beneficiary($section, ['name' => 'Paid often']);
        $quiet = $this->beneficiary($section, ['name' => 'Paid once']);

        app(PaymentService::class)->approve($this->payment($busy, 29_000, '2026-07-10'));
        app(PaymentService::class)->approve($this->payment($quiet, 5_000, '2026-08-10'));

        $this->assertSame(0, WithholdingDeduction::count());
    }

    /** A payment of nothing withholds nothing, and does not fail trying. */
    public function test_a_zero_payment_is_not_withheld_from(): void
    {
        $section = $this->section(rateNonFiler: 11);

        $payment = $this->payment($this->beneficiary($section), 0);

        $this->assertNull(app(PaymentService::class)->approve($payment)->journal_entry_id);
        $this->assertSame(0, WithholdingDeduction::count());
    }

    /**
     * A section with nowhere to post refuses the approval rather than skipping the deduction.
     *
     * The same position `postEntryFor()` takes when account 1100 is missing, and for the same reason: a
     * payment that quietly does not withhold is a liability nobody knows about, while a payment that refuses
     * to be approved is a sentence on a screen.
     */
    public function test_a_section_with_no_posting_account_refuses_the_approval(): void
    {
        $section = $this->section(rateNonFiler: 11);
        $section->update(['account_id' => null]);
        Account::where('code', WithholdingSection::DEFAULT_ACCOUNT_CODE)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no posting account');

        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 10_000));
    }

    // ──────────────────────────────────────────────── the bank transfer ──

    /**
     * The bank file pays the net.
     *
     * The payment's own amount stays the gross — that is what the company owes and what the entry debits —
     * and the transfer is smaller by the tax the company keeps back to remit. A file that paid the gross
     * would leave the cash account disagreeing with the bank statement by exactly the withheld figure.
     */
    public function test_the_bank_file_pays_the_net(): void
    {
        $section = $this->section(rateNonFiler: 20);
        $payment = $this->payment($this->beneficiary($section), 100_000);

        app(PaymentService::class)->approve($payment);

        $payment = Payment::with('withholdingDeduction')->findOrFail($payment->getKey());

        $this->assertSame(100_000.0, (float) $payment->amount);
        $this->assertSame(80_000.0, $payment->transferAmount());
    }

    /** And a payment with no deduction transfers its amount, unchanged. */
    public function test_a_payment_without_a_deduction_transfers_its_amount(): void
    {
        $payment = $this->payment($this->beneficiary(), 42_500);

        $this->assertSame(42_500.0, $payment->transferAmount());
    }

    // ──────────────────────────────────────────────── the §165 statement ──

    /** The statement lists each deduction with what the authority asks for, and totals them. */
    public function test_the_statement_lists_every_deduction_in_the_period(): void
    {
        $section = $this->section(rateNonFiler: 11);
        $beneficiary = $this->beneficiary($section, ['id_number' => '1234567-8']);

        app(PaymentService::class)->approve($this->payment($beneficiary, 40_000, '2026-08-10'));
        app(PaymentService::class)->approve($this->payment($beneficiary, 60_000, '2026-09-10'));

        $statement = app(WithholdingService::class)->statement('2026-07-01', '2026-09-30');

        $this->assertCount(2, $statement['rows']);
        $this->assertSame(100_000.0, $statement['totals']['taxable']);
        $this->assertSame(11_000.0, $statement['totals']['withheld']);
        $this->assertSame(1, $statement['totals']['payees']);
        $this->assertSame(2, $statement['totals']['non_filers']);
        $this->assertSame('1234567-8', $statement['rows'][0]['identity']);
        $this->assertSame(['153(1)(b)' => 11_000.0], $statement['sections']);
    }

    /** A period the deduction is outside of does not include it. */
    public function test_the_statement_is_bounded_by_its_period(): void
    {
        $section = $this->section(rateNonFiler: 11);

        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 40_000, '2026-08-10'));

        $this->assertSame(0, app(WithholdingService::class)->statement('2026-09-01', '2026-09-30')['totals']['count']);
    }

    /** Drawn as a table in the pane, with the month as its filter — like the other statutory returns. */
    public function test_the_statement_is_a_report_in_the_hub(): void
    {
        $section = $this->section(rateNonFiler: 11);
        app(PaymentService::class)->approve($this->payment($this->beneficiary($section), 40_000, '2026-08-10'));

        $this->assertTrue(ReportPane::supports('WithholdingStatement'));
        $this->assertSame(['month'], ReportPane::asks('WithholdingStatement'));

        $payload = app(WithholdingReports::class)->statement(self::AS_OF, 'August');

        $this->assertSame('table', $payload['kind']);
        $this->assertSame('Tax Withheld (§165)', $payload['title']);
        $this->assertStringContainsString('August 2026', $payload['subtitle']);
        $this->assertCount(1, $payload['rows']);
        $this->assertSame('11%', $payload['rows'][0][5]);
        $this->assertSame(4_400.0, $payload['tiles'][0]['value']);

        // With no month, the fiscal year to date — §165 is filed monthly and reconciled for the year.
        $this->assertCount(1, app(WithholdingReports::class)->statement(self::AS_OF)['rows']);
    }

    /** With nothing withheld, the report says which of the two reasons that is. */
    public function test_an_empty_statement_says_why_it_is_empty(): void
    {
        $payload = app(WithholdingReports::class)->statement(self::AS_OF);

        $this->assertSame([], $payload['rows']);
        $this->assertStringContainsString('NO WITHHOLDING SECTION IS ASSIGNED', $payload['note']);
    }

    // ────────────────────────────────────────────────── the rate table ──

    /**
     * The seeded sections, and the fact that seeding them withholds nothing.
     *
     * Reference data rather than company data — national law, like the banks and the tax schedule beside it
     * in the baseline. What belongs to a company is which supplier each section applies to.
     */
    public function test_the_seeded_sections_withhold_nothing_by_themselves(): void
    {
        $this->seed(WithholdingSectionSeeder::class);

        $this->assertGreaterThanOrEqual(4, WithholdingSection::count());
        $this->assertTrue(WithholdingSection::on(self::AS_OF)->where('section', '153(1)(b)')->exists());

        app(PaymentService::class)->approve($this->payment($this->beneficiary(), 100_000));

        $this->assertSame(0, WithholdingDeduction::count());
    }

    /** Re-running the seeder does not switch a section a company turned off back on. */
    public function test_re_seeding_leaves_a_company_s_own_choices_alone(): void
    {
        $this->seed(WithholdingSectionSeeder::class);

        $section = WithholdingSection::where('section', '153(1)(c)')->firstOrFail();
        $section->update(['is_active' => false]);

        $this->seed(WithholdingSectionSeeder::class);

        $this->assertFalse($section->fresh()->is_active);
    }

    // ───────────────────────────────────────────────────────── fixtures ──

    private function section(
        float $rateFiler = 11,
        float $rateNonFiler = 22,
        ?float $perPayment = null,
        ?float $annual = null,
    ): WithholdingSection {
        return WithholdingSection::create([
            'section' => '153(1)(b)',
            'label' => 'Services rendered',
            'rate_filer' => $rateFiler,
            'rate_non_filer' => $rateNonFiler,
            'per_payment_threshold' => $perPayment,
            'annual_threshold' => $annual,
            'account_id' => Account::where('code', WithholdingSection::DEFAULT_ACCOUNT_CODE)->value('id'),
            'effective_from' => '2026-07-01',
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function beneficiary(?WithholdingSection $section = null, array $attributes = []): Beneficiary
    {
        return Beneficiary::create([
            'name' => 'A supplier',
            'id_type' => 'NTN',
            'payment_type' => 'IBFT',
            'is_active' => true,
            'withholding_section_id' => $section?->getKey(),
            ...$attributes,
        ]);
    }

    private function payment(Beneficiary $beneficiary, float $amount, ?string $date = null): Payment
    {
        return Payment::create([
            'payable_type' => \App\Support\ModuleMap::alias(Beneficiary::class),
            'payable_id' => $beneficiary->getKey(),
            'transaction_type_id' => $this->type->getKey(),
            'amount' => $amount,
            'details' => 'September consultancy',
            'value_date' => $date ?? self::AS_OF,
            'status' => Payment::STATUS_DRAFT,
        ]);
    }

    private function debitTo(\App\Modules\Accounting\Models\JournalEntry $entry, string ...$codes): float
    {
        return $this->lineFor($entry, $codes)->sum('debit_amount');
    }

    private function creditTo(\App\Modules\Accounting\Models\JournalEntry $entry, string ...$codes): float
    {
        return $this->lineFor($entry, $codes)->sum('credit_amount');
    }

    /**
     * @param  array<int, string>  $codes
     * @return \Illuminate\Support\Collection<int, \App\Modules\Accounting\Models\JournalEntryLine>
     */
    private function lineFor(\App\Modules\Accounting\Models\JournalEntry $entry, array $codes): \Illuminate\Support\Collection
    {
        $ids = Account::whereIn('code', $codes)->pluck('id')->all();

        return $entry->lines->whereIn('account_id', $ids);
    }
}
