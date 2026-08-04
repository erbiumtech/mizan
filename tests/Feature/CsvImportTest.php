<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Core\Services\CsvImportService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\Contact;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Getting a company's existing records in at setup.
 *
 * The GnuCash importer is the hard version of this — a whole book with accounts and
 * history — and nobody setting up needs it. What they have is a spreadsheet of clients,
 * a spreadsheet of products, and a trial balance from whatever they used before.
 */
class CsvImportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'import@test.local'));
        $this->setCurrentTenant();
    }

    private function imports(): CsvImportService
    {
        return app(CsvImportService::class);
    }

    // ---- Reading the file --------------------------------------------------

    /** A spreadsheet exported twice rarely has its columns in the same order. */
    public function test_columns_are_found_by_their_header_not_their_position(): void
    {
        $csv = "email,name,phone\nanna@erbium.example,Erbium AG,+41 44 000 0000\n";

        $result = $this->imports()->import($csv, CsvImportService::TYPE_CONTACTS);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('anna@erbium.example', Contact::where('name', 'Erbium AG')->value('email'));
    }

    public function test_extra_columns_are_ignored(): void
    {
        $csv = "name,their_internal_id,email\nErbium AG,XYZ-9,billing@erbium.example\n";

        $this->assertSame(1, $this->imports()->import($csv, CsvImportService::TYPE_CONTACTS)['imported']);
    }

    public function test_a_file_missing_the_required_column_says_which(): void
    {
        $this->expectExceptionMessage('has no "name" column');

        $this->imports()->read("email,phone\na@b.example,123\n", CsvImportService::TYPE_CONTACTS);
    }

    public function test_a_file_with_no_rows_says_so(): void
    {
        $this->expectExceptionMessage('header and no rows');

        $this->imports()->read("name,email\n", CsvImportService::TYPE_CONTACTS);
    }

    public function test_blank_lines_are_skipped_rather_than_imported_as_empty_rows(): void
    {
        $csv = "name\nErbium AG\n\n4sure AG\n";

        $this->assertSame(2, $this->imports()->import($csv, CsvImportService::TYPE_CONTACTS)['imported']);
    }

    // ---- What it does with bad rows ----------------------------------------

    /** A typo on line 40 should not cost the other 39. */
    public function test_a_bad_row_is_named_and_skipped_while_the_rest_import(): void
    {
        $csv = "name,email\nErbium AG,billing@erbium.example\n,orphan@erbium.example\n4sure AG,not-an-email\n";

        $result = $this->imports()->import($csv, CsvImportService::TYPE_CONTACTS);

        $this->assertSame(1, $result['imported']);
        $this->assertCount(2, $result['skipped']);
        $this->assertStringContainsString('Line 3: no name', $result['skipped'][0]);
        $this->assertStringContainsString('Line 4', $result['skipped'][1]);
        $this->assertStringContainsString('not an email address', $result['skipped'][1]);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $csv = "name,email\nErbium AG,billing@erbium.example\n";

        $preview = $this->imports()->preview($csv, CsvImportService::TYPE_CONTACTS);

        $this->assertSame(1, $preview['ready']);
        $this->assertSame(0, $preview['skipped']);
        $this->assertSame(0, Contact::count(), 'nothing was written');
    }

    public function test_the_preview_says_what_is_wrong_with_each_row(): void
    {
        $preview = $this->imports()->preview("name\nErbium AG\n\n", CsvImportService::TYPE_CONTACTS);

        $this->assertNull($preview['rows'][0]['_problem']);
    }

    // ---- Contacts and products ---------------------------------------------

    public function test_running_the_same_file_twice_corrects_rather_than_duplicates(): void
    {
        $this->imports()->import("name,email\nErbium AG,old@erbium.example\n", CsvImportService::TYPE_CONTACTS);
        $this->imports()->import("name,email\nErbium AG,new@erbium.example\n", CsvImportService::TYPE_CONTACTS);

        $this->assertSame(1, Contact::count());
        $this->assertSame('new@erbium.example', Contact::first()->email);
    }

    public function test_an_unrecognised_kind_falls_back_to_customer(): void
    {
        // Rather than refusing the row: somebody's spreadsheet saying "Client" should
        // not cost them the import.
        $this->imports()->import("name,kind\nErbium AG,Client\n", CsvImportService::TYPE_CONTACTS);

        $this->assertSame(Contact::KIND_CUSTOMER, Contact::first()->kind);
    }

    public function test_products_import_by_sku(): void
    {
        $csv = "sku,name,unit\nSKU-001,Laptop stand,pcs\nSKU-002,Desk lamp,\n";

        $this->assertSame(2, $this->imports()->import($csv, CsvImportService::TYPE_PRODUCTS)['imported']);
        $this->assertSame('pcs', Product::where('sku', 'SKU-002')->value('unit'), 'the default unit');
    }

    public function test_a_product_with_no_sku_is_skipped(): void
    {
        $result = $this->imports()->import("sku,name\n,Nameless\n", CsvImportService::TYPE_PRODUCTS);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('no SKU', $result['skipped'][0]);
    }

    // ---- Opening balances --------------------------------------------------

    public function test_opening_balances_post_one_balanced_entry(): void
    {
        $csv = "account_code,debit,credit\n1100,250000.00,\n2400,,90000.00\n";

        $result = $this->imports()->import($csv, CsvImportService::TYPE_OPENING_BALANCES, '2026-06-30');

        $this->assertSame(2, $result['imported']);

        $reports = app(FinancialReportService::class);
        $this->assertTrue($reports->trialBalance('2026-06-30')['balanced']);
        $this->assertSame(250000.0, $reports->cashAt('2026-06-30'));
    }

    /**
     * A trial balance that does not add up is normal when only some accounts have been
     * entered — the difference belongs in Opening Balance Equity, which the trial
     * balance and balance sheet both already report on, rather than as an imbalance
     * nobody can see.
     */
    public function test_what_does_not_balance_lands_in_opening_balance_equity(): void
    {
        $csv = "account_code,debit,credit\n1100,250000.00,\n";

        $this->imports()->import($csv, CsvImportService::TYPE_OPENING_BALANCES, '2026-06-30');

        $report = app(FinancialReportService::class)->trialBalance('2026-06-30');

        $this->assertTrue($report['balanced']);
        $this->assertSame(250000.0, $report['opening_balance_equity']['balance']);
        $this->assertFalse($report['opening_balance_equity']['is_clear'], 'and it says the book is half-entered');
    }

    public function test_a_row_naming_an_account_that_does_not_exist_is_skipped(): void
    {
        $result = $this->imports()->import(
            "account_code,debit,credit\n9999,1000,\n",
            CsvImportService::TYPE_OPENING_BALANCES,
        );

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('no account with code 9999', $result['skipped'][0]);
    }

    public function test_a_row_with_both_debit_and_credit_is_skipped(): void
    {
        $result = $this->imports()->import(
            "account_code,debit,credit\n1100,1000,500\n",
            CsvImportService::TYPE_OPENING_BALANCES,
        );

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('both debit and credit', $result['skipped'][0]);
    }

    public function test_a_negative_amount_is_refused_with_advice(): void
    {
        $result = $this->imports()->import(
            "account_code,debit,credit\n1100,-1000,\n",
            CsvImportService::TYPE_OPENING_BALANCES,
        );

        $this->assertStringContainsString('other column instead', $result['skipped'][0]);
    }

    public function test_thousands_separators_are_read(): void
    {
        // Because spreadsheets export them and refusing the row over a comma is a poor
        // trade for the person retyping the file.
        $this->imports()->import(
            "account_code,debit,credit\n1100,\"250,000.00\",\n",
            CsvImportService::TYPE_OPENING_BALANCES,
            '2026-06-30',
        );

        $this->assertSame(250000.0, app(FinancialReportService::class)->cashAt('2026-06-30'));
    }

    // ---- Templates ---------------------------------------------------------

    public function test_every_type_offers_a_template_that_imports(): void
    {
        // A file somebody can fill in, rather than a format they have to guess — and it
        // has to be a file this importer accepts, which is what makes it worth shipping.
        foreach (array_keys(CsvImportService::LABELS) as $type) {
            $template = $this->imports()->template($type);

            $this->assertStringContainsString(CsvImportService::COLUMNS[$type][0], $template);
            $this->assertSame(0, $this->imports()->preview($template, $type)['skipped'], "{$type} template");
        }
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->expectExceptionMessage('Unknown import type');

        $this->imports()->template('payslips');
    }
}
