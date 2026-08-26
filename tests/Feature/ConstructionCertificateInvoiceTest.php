<?php

namespace Tests\Feature;

use App\Modules\Accounting\Support\ConstructionAccounts;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\ListPaymentCertificates;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\CertificateInvoiceService;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Database\Seeders\ConstructionAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Where construction stops and `invoices` takes over — `docs/construction-management-plan.md` §10.4, Phase 4f.
 *
 * **The rule this file exists for is the one an audit looks for**: the work is invoiced **gross** with retention as
 * its own line against an asset, never net. Invoicing net understates revenue and turnover by up to a tenth for the
 * whole life of the job, and then makes the release invoice look like revenue recognised in a period when no work
 * happened.
 *
 * Two more, each a way of billing the same money twice:
 *
 *  - **The period movement is invoiced, not the cumulative figure**, and the `previous_certificates` deduction row
 *    is therefore not an invoice line — it is the mechanism that turns one into the other.
 *  - **It stops at draft and `invoice_id` prevents a second conversion**, copied from
 *    `QuotationService::convertToInvoice()` down to the refusal.
 */
class ConstructionCertificateInvoiceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contract $contract;

    private CertificateInvoiceService $handoff;

    /** Memoised: the certificate series is unique per contract, so building it twice in one test is a collision. */
    private ?PaymentCertificate $certificate = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'handoff@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'invoicing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        // The construction accounts on top of the chart the base test case seeds — a separate seeder, because a
        // dozen construction accounts in every bookkeeping company's chart is noise (§18.2).
        $this->seed(ConstructionAccountsSeeder::class);

        $client = Contact::create(['name' => 'Employer Ltd', 'type' => 'customer']);

        $contracts = app(ContractService::class);
        $this->contract = $contracts->create(Job::create(['code' => 'J-1', 'name' => 'Tower']), [
            'title' => 'Main works',
            'contact_id' => $client->getKey(),
            'contract_sum' => 500_000_000,
            'retention_percent' => 10,
            'payment_terms_days' => 56,
        ]);
        $contracts->addItem($this->contract, [
            'item_no' => '1', 'description' => 'The works', 'scheduled_value' => 500_000_000,
        ]);
        $contracts->execute($this->contract);
        $this->contract->refresh();

        $this->handoff = app(CertificateInvoiceService::class);
    }

    /**
     * §10.4's worked example, which is §8.4's certificate 7 seen from the invoice side.
     *
     * Gross to date 215,600,000 against 184,900,000 previously certified — a period movement of 30,700,000 — less
     * retention 2,140,000, advance recovery 5,350,000 and an NCR deduction of 850,000, leaving 22,360,000.
     */
    private function certificateSeven(): PaymentCertificate
    {
        if ($this->certificate !== null) {
            return $this->certificate->refresh();
        }

        $certificate = PaymentCertificate::create([
            'contract_id' => $this->contract->getKey(),
            'certificate_number' => 'IPC-7',
            'sequence' => 7,
            'period_end' => '2026-06-30',
            'issued_on' => '2026-07-05',
            'due_on' => '2026-08-30',
            'status' => PaymentCertificate::STATUS_ISSUED,
            'contract_sum_original' => 500_000_000,
            'variations_net_to_date' => 13_020_000,
            'gross_work_to_date' => 212_400_000,
            'gross_materials_to_date' => 3_200_000,
            'gross_value_to_date' => 215_600_000,
            'retention_to_date' => 21_560_000,
            'previously_certified' => 184_900_000,
            // The gross the movement rows net against. §8.4's own arithmetic implies this figure:
            // 215,600,000 − 2,140,000 − 5,350,000 − 850,000 − 22,360,000 = 184,900,000. The plan's
            // numbers were written for movement rows all along; only the label said otherwise.
            'previous_gross_value_to_date' => 184_900_000,
            'current_due' => 22_360_000,
        ]);

        foreach ([
            [CertificateDeduction::KIND_RETENTION, 'Less retention @ 10% (cl. 14.3)', -2_140_000],
            [CertificateDeduction::KIND_ADVANCE_RECOVERY, 'Less advance payment recovery (cl. 14.2)', -5_350_000],
            [CertificateDeduction::KIND_NCR, 'Less deduction, NCR-0012 (cl. 14.6)', -850_000],
            [CertificateDeduction::KIND_PREVIOUS_CERTIFICATES, 'Less previously certified', -184_900_000],
        ] as [$kind, $description, $amount]) {
            $certificate->deductions()->create(['kind' => $kind, 'description' => $description, 'amount' => $amount]);
        }

        return $this->certificate = $certificate->refresh();
    }

    private function lineOn(Invoice $invoice, string $needle): ?object
    {
        return $invoice->lines->first(fn ($line): bool => str_contains($line->description, $needle));
    }

    // ------------------------------------------------------------------ the map

    /** The account map answers by semantics, and the shipped defaults resolve against the seeded chart. */
    public function test_the_construction_accounts_resolve(): void
    {
        $this->assertSame('4600', ConstructionAccounts::code('contract_revenue'));
        $this->assertSame('1620', ConstructionAccounts::code('retention_receivable'));
        $this->assertIsInt(ConstructionAccounts::id('retention_receivable'));
        $this->assertTrue(ConstructionAccounts::isConfigured());
    }

    /**
     * The payable side's own accounts resolve too, including the one that had to be added for it.
     *
     * `subcontract_advance` is the mirror of `contract_liabilities`: 2630 is an advance *received* and is a
     * liability, so an advance *paid* down to a subcontractor cannot share it — the two are opposite sides of the
     * balance sheet and netting them would hide both.
     */
    public function test_the_payable_construction_accounts_resolve(): void
    {
        $this->assertSame('2620', ConstructionAccounts::code('retention_payable'));
        $this->assertSame('1630', ConstructionAccounts::code('subcontract_advance'));
        $this->assertSame('5650', ConstructionAccounts::code('job_cost_subcontract'));

        // Seeded, so the defaults are reachable rather than merely configured.
        $this->assertIsInt(ConstructionAccounts::id('retention_payable'));
        $this->assertIsInt(ConstructionAccounts::id('subcontract_advance'));
        $this->assertIsInt(ConstructionAccounts::id('job_cost_subcontract'));
    }

    /** The five job-cost accounts are reached by cost type rather than by composing a key. */
    public function test_job_cost_accounts_are_resolved_by_cost_type(): void
    {
        $this->assertSame(
            ConstructionAccounts::id('job_cost_labour'),
            ConstructionAccounts::jobCostIdFor('labour'),
        );

        // An unknown type falls back to "other" rather than throwing: a cost type this map has not heard of is
        // still a cost, and refusing to book it would lose it.
        $this->assertSame(
            ConstructionAccounts::id('job_cost_other'),
            ConstructionAccounts::jobCostIdFor('welfare'),
        );
    }

    /**
     * A missing account names the settings page **and** the seeder.
     *
     * §18.2 asks for exactly this: "account 5100 cannot accept entries" names neither the caller nor the fix.
     */
    public function test_a_missing_account_says_where_to_fix_it(): void
    {
        config(['accounting.construction_accounts.retention_receivable' => '9999']);

        try {
            ConstructionAccounts::id('retention_receivable');
            $this->fail('A code that is not in the chart should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('9999', $e->getMessage());
            $this->assertStringContainsString('Company Settings → Construction', $e->getMessage());
            $this->assertStringContainsString('ConstructionAccountsSeeder', $e->getMessage());
        }
    }

    /** An account that cannot take an entry is caught here, where the message can name the construction line. */
    public function test_a_group_header_is_refused_with_a_readable_reason(): void
    {
        // 4000 is the income group header, which takes no manual entry.
        config(['accounting.construction_accounts.contract_revenue' => '4000']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Construction account 'contract_revenue'");

        ConstructionAccounts::id('contract_revenue');
    }

    public function test_an_unknown_key_is_refused_rather_than_falling_back(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a construction account key');

        ConstructionAccounts::code('retention_recievable');
    }

    // ------------------------------------------------------------ §10.4's four lines

    /**
     * **The exit condition of this sub-phase.** §10.4's table, line for line.
     *
     * | Line | Amount | Account |
     * |---|---|---|
     * | Work executed to 30 Jun per IPC-007 | +30,700,000 | contract revenue |
     * | Less retention @ 10% (cl. 14.3) | −2,140,000 | retention receivable — an asset |
     * | Less advance payment recovery (cl. 14.2) | −5,350,000 | advance payment received — a liability |
     * | Less deduction, NCR-0012 (cl. 14.6) | −850,000 | contract revenue |
     */
    public function test_the_invoice_reproduces_the_worked_example(): void
    {
        $invoice = $this->handoff->raise($this->certificateSeven());

        $this->assertCount(4, $invoice->lines, 'one line per deduction group, never one per contract item');

        $work = $this->lineOn($invoice, 'Work executed');
        $this->assertEquals(30_700_000, $work->line_total, 'the period movement, gross of retention');
        $this->assertSame(ConstructionAccounts::id('contract_revenue'), $work->account_id);

        $retention = $this->lineOn($invoice, 'retention');
        $this->assertEquals(-2_140_000, $retention->line_total);
        // The whole of §10.4: an asset, not a smaller invoice.
        $this->assertSame(ConstructionAccounts::id('retention_receivable'), $retention->account_id);

        $advance = $this->lineOn($invoice, 'advance payment recovery');
        $this->assertEquals(-5_350_000, $advance->line_total);
        $this->assertSame(ConstructionAccounts::id('contract_liabilities'), $advance->account_id);

        $ncr = $this->lineOn($invoice, 'NCR-0012');
        $this->assertEquals(-850_000, $ncr->line_total);
        $this->assertSame(ConstructionAccounts::id('contract_revenue'), $ncr->account_id);

        // 30,700,000 − 2,140,000 − 5,350,000 − 850,000, which is the certificate's own bottom line.
        $this->assertEquals(22_360_000, $invoice->total);
        $this->assertEquals(22_360_000, $this->certificateSeven()->current_due);
    }

    /**
     * **Gross, not net**, stated as its own assertion because it is the misstatement §10.4 is written to prevent.
     *
     * The revenue line is the whole period movement; the retention is a separate line against an asset. A net
     * invoice would show 28,560,000 of revenue and no retention anywhere.
     */
    public function test_the_revenue_line_is_gross_and_retention_is_a_separate_asset_line(): void
    {
        $invoice = $this->handoff->raise($this->certificateSeven());

        $revenueLines = $invoice->lines->where('account_id', ConstructionAccounts::id('contract_revenue'));

        $this->assertEquals(30_700_000 - 850_000, $revenueLines->sum('line_total'));
        $this->assertNotEquals(
            22_360_000,
            $this->lineOn($invoice, 'Work executed')->line_total,
            'the revenue line must not be the net payable figure',
        );
        $this->assertEquals(
            -2_140_000,
            $invoice->lines->where('account_id', ConstructionAccounts::id('retention_receivable'))->sum('line_total'),
        );
    }

    /**
     * The `previous_certificates` row is not an invoice line.
     *
     * It is how a cumulative certificate expresses a period figure. Billing it *and* the period movement would
     * deduct 184,900,000 from an invoice that never included it.
     */
    public function test_previously_certified_is_not_billed_as_a_deduction(): void
    {
        $invoice = $this->handoff->raise($this->certificateSeven());

        $this->assertNull($this->lineOn($invoice, 'previously certified'));
        $this->assertGreaterThan(0, $invoice->total, 'billing it would have made the invoice deeply negative');
    }

    /** Withholding is the client's own deduction against the invoice, not a reduction of the work. */
    public function test_tax_withheld_is_left_to_whoever_records_the_receipt(): void
    {
        $certificate = $this->certificateSeven();
        $certificate->deductions()->create([
            'kind' => CertificateDeduction::KIND_TAX_WITHHELD,
            'description' => 'Less income tax withheld @ 7.5%',
            'amount' => -1_680_000,
        ]);

        $invoice = $this->handoff->raise($certificate->refresh());

        $this->assertNull($this->lineOn($invoice, 'withheld'));
        $this->assertEquals(22_360_000, $invoice->total);
    }

    // ------------------------------------------------------------------ the boundary

    /** It stops at draft: issuing transmits, and transmission is what cannot be undone. */
    public function test_the_invoice_stops_at_draft(): void
    {
        $invoice = $this->handoff->raise($this->certificateSeven());

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame(Invoice::KIND_SALE, $invoice->kind);
        $this->assertNull($invoice->journal_entry_id, 'nothing has reached the ledger');
        $this->assertSame('2026-07-05', $invoice->invoice_date->toDateString(), "the certificate's issue date");
        $this->assertSame('2026-08-30', $invoice->due_date->toDateString(), 'and its own due date');
    }

    /** A payable certificate becomes a purchase invoice — `invoices.kind`, one table for both sides. */
    public function test_a_payable_certificate_becomes_a_purchase_invoice(): void
    {
        $this->contract->update(['side' => Contract::SIDE_PAYABLE]);

        $invoice = $this->handoff->raise($this->certificateSeven());

        $this->assertSame(Invoice::KIND_PURCHASE, $invoice->kind);
    }

    /**
     * **The regression this pair of tests exists for.**
     *
     * This service branched `invoices.kind` on the side and then mapped every line to the *receivable* accounts
     * regardless. On a purchase invoice the line account is debited, so a subcontractor's certificate debited
     * `contract_revenue` — reducing income instead of recognising cost — and debited `retention_receivable`, an
     * asset, for retention this company *owes*. The ledger balanced and both accounts were wrong, which is exactly
     * why it survived: the only test on this path asserted `kind` and nothing else.
     *
     * Downward the money means the opposite thing at every line. The work is job cost; the retention is a
     * liability; the advance recovered is an asset we paid down and are getting back through his certificates.
     */
    public function test_a_payable_certificate_maps_every_line_to_the_payable_accounts(): void
    {
        $this->contract->update(['side' => Contract::SIDE_PAYABLE]);

        $invoice = $this->handoff->raise($this->certificateSeven());

        $work = $this->lineOn($invoice, 'Work executed');
        $this->assertEquals(30_700_000, $work->line_total, 'still gross, still the period movement');
        $this->assertSame(
            ConstructionAccounts::id('job_cost_subcontract'),
            $work->account_id,
            'a subcontractor\'s certified work is what the job cost, not a reduction of our turnover',
        );

        $retention = $this->lineOn($invoice, 'retention');
        $this->assertEquals(-2_140_000, $retention->line_total);
        $this->assertSame(
            ConstructionAccounts::id('retention_payable'),
            $retention->account_id,
            'retention we hold from a subcontractor is money we owe him, not an asset somebody owes us',
        );

        $advance = $this->lineOn($invoice, 'advance payment recovery');
        $this->assertEquals(-5_350_000, $advance->line_total);
        $this->assertSame(
            ConstructionAccounts::id('subcontract_advance'),
            $advance->account_id,
            'an advance paid down is ours until his certificates recover it — the mirror of contract_liabilities',
        );

        // A deduction that reduces what we owe him goes back against the job cost it was booked to.
        $ncr = $this->lineOn($invoice, 'NCR-0012');
        $this->assertEquals(-850_000, $ncr->line_total);
        $this->assertSame(ConstructionAccounts::id('job_cost_subcontract'), $ncr->account_id);

        $this->assertEquals(22_360_000, $invoice->total, 'the net payable is unchanged by any of this');
    }

    /**
     * Stated as its own assertion because it is the shape of the bug rather than one line of it.
     *
     * Every receivable account is a *credit* the company is owed or has earned. None of them may appear on a
     * certificate for money going out, whatever the deduction kinds happen to be.
     */
    public function test_the_payable_side_never_touches_a_receivable_account(): void
    {
        $this->contract->update(['side' => Contract::SIDE_PAYABLE]);

        $invoice = $this->handoff->raise($this->certificateSeven());

        $forbidden = [
            'contract_revenue' => ConstructionAccounts::id('contract_revenue'),
            'retention_receivable' => ConstructionAccounts::id('retention_receivable'),
            'contract_liabilities' => ConstructionAccounts::id('contract_liabilities'),
        ];

        foreach ($forbidden as $key => $accountId) {
            $this->assertSame(
                0,
                $invoice->lines->where('account_id', $accountId)->count(),
                "a payable certificate put a line on {$key}, which belongs to the receivable side",
            );
        }
    }

    /** And the receivable side is untouched by the split — the worked example above still holds. */
    public function test_the_receivable_side_still_maps_to_the_receivable_accounts(): void
    {
        $this->assertSame(Contract::SIDE_RECEIVABLE, $this->contract->side);

        $invoice = $this->handoff->raise($this->certificateSeven());

        $this->assertSame(
            ConstructionAccounts::id('contract_revenue'),
            $this->lineOn($invoice, 'Work executed')->account_id,
        );
        $this->assertSame(
            ConstructionAccounts::id('retention_receivable'),
            $this->lineOn($invoice, 'retention')->account_id,
        );
        $this->assertSame(
            0,
            $invoice->lines->where('account_id', ConstructionAccounts::id('retention_payable'))->count(),
            'money coming in never credits the retention we hold from somebody else',
        );
    }

    /**
     * A deduction kind the map has not heard of lands on the work account **for its own side**.
     *
     * The old fallback was a fixed `contract_revenue`, which on a payable certificate is the same defect in
     * miniature: a kind added later would silently debit income on a subcontractor's bill.
     */
    public function test_an_unmapped_deduction_kind_falls_back_to_its_own_sides_work_account(): void
    {
        $this->contract->update(['side' => Contract::SIDE_PAYABLE]);

        $certificate = $this->certificateSeven();
        $certificate->deductions()->create([
            'kind' => CertificateDeduction::KIND_CONTRA_CHARGE,
            'description' => 'Less contra charge for site welfare',
            'amount' => -120_000,
        ]);

        $invoice = $this->handoff->raise($certificate->refresh());

        $this->assertSame(
            ConstructionAccounts::id('job_cost_subcontract'),
            $this->lineOn($invoice, 'contra charge')->account_id,
        );
    }

    public function test_a_second_conversion_is_refused(): void
    {
        $certificate = $this->certificateSeven();
        $this->handoff->raise($certificate);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bill the same money twice');

        $this->handoff->raise($certificate->refresh());
    }

    public function test_a_draft_certificate_is_not_billable(): void
    {
        $certificate = $this->certificateSeven();
        $certificate->update(['status' => PaymentCertificate::STATUS_DRAFT]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an issued certificate is billable');

        $this->handoff->raise($certificate->refresh());
    }

    public function test_a_contract_with_no_other_party_has_nobody_to_invoice(): void
    {
        $this->contract->update(['contact_id' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names no other party');

        $this->handoff->raise($this->certificateSeven());
    }

    /**
     * Without Invoicing the hand-off refuses in one sentence and the certificate stands.
     *
     * §18's fork: a payment certificate is not a quote. It starts the payment period and an adjudicator reads it,
     * whether or not anybody raises a tax invoice.
     */
    public function test_without_invoicing_the_certificate_is_still_the_deliverable(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'invoicing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse($this->handoff->canRaise());

        try {
            $this->handoff->raise($this->certificateSeven());
            $this->fail('The hand-off should refuse without Invoicing.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('without the Invoicing module', $e->getMessage());
            $this->assertStringContainsString('The certificate itself stands', $e->getMessage());
        }

        // And the certificate is untouched: no half-conversion left behind.
        $this->assertNull($this->certificateSeven()->invoice_id);
    }

    // ------------------------------------------------------------------ the settings block

    /**
     * The map is editable on Core's settings page, contributed by this module.
     *
     * §18.2's refusal to add `'core' => [… 'construction']` is what the `SettingsSection` registry exists to make
     * possible: Core keeps the page, the module that owns the setting brings the block.
     */
    public function test_the_account_map_is_editable_on_the_settings_page(): void
    {
        $section = app(\App\Modules\ConstructionContracts\Filament\Settings\ConstructionAccountsSettingsSection::class);

        $this->assertSame('construction.accounts', $section->key());
        $this->assertArrayHasKey('accounting_construction_accounts', $section->fill());

        // Every key the resolver answers to is on the form, or a company could not set one at all — the keys are
        // deliberately not addable.
        $this->assertSame(
            array_keys(ConstructionAccounts::KEYS),
            array_keys($section->fill()['accounting_construction_accounts']),
        );

        $section->save(['accounting_construction_accounts' => ['retention_receivable' => '1600'] + $section->fill()['accounting_construction_accounts']]);

        $this->assertSame('1600', ConstructionAccounts::code('retention_receivable'), 'the saved code wins');
    }

    /** And it is registered against the page rather than only existing. */
    public function test_the_settings_block_is_registered(): void
    {
        $keys = array_map(
            fn (array $entry): string => $entry['section']->key(),
            \App\Support\SettingsSections::sorted(),
        );

        $this->assertContains('construction.accounts', $keys);
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_raise_invoice_action_produces_the_draft(): void
    {
        $certificate = $this->certificateSeven();

        Livewire::test(ListPaymentCertificates::class)
            ->callTableAction('invoice', $certificate);

        $certificate->refresh();

        $this->assertNotNull($certificate->invoice_id);
        $this->assertEquals(22_360_000, Invoice::query()->findOrFail($certificate->invoice_id)->total);
    }

    /** The action is absent without Invoicing rather than present and failing (§18.1). */
    public function test_the_action_is_absent_without_invoicing(): void
    {
        $certificate = $this->certificateSeven();

        Livewire::test(ListPaymentCertificates::class)
            ->assertActionVisible(TestAction::make('invoice')->table($certificate));

        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'invoicing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        Livewire::test(ListPaymentCertificates::class)
            ->assertActionHidden(TestAction::make('invoice')->table($certificate));
    }

    /**
     * And once invoiced, the action goes: the policy refuses a second conversion before the service has to.
     *
     * Asked of a CEO rather than an Administrator, because `AppServiceProvider` waves an Administrator through
     * every ability except `create` — so a refusal asserted as one asserts nothing. The CEO is the role that
     * actually holds `ConstructionCertificateInvoice`.
     */
    public function test_the_action_goes_once_the_certificate_is_invoiced(): void
    {
        $certificate = $this->certificateSeven();
        $this->handoff->raise($certificate);

        (new \Database\Seeders\RoleSeeder)->run();
        $this->actingAs($this->makeUser('CEO', 'handoff-ceo@test.local'));

        Livewire::test(ListPaymentCertificates::class)
            ->assertActionHidden(TestAction::make('invoice')->table($certificate->refresh()));
    }
}
