<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Quotations\Filament\Pages\QuotationConversion;
use App\Modules\Quotations\Models\Quotation;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Quotation conversion — `docs/reports-expansion-plan.md` Phase 3.3.
 *
 * The report has one rule that makes it worth building and three that keep it honest.
 *
 * **Worth building:** superseded versions are excluded. A quote revised three times is one opportunity, and
 * counting each version would inflate what was issued by however often the company negotiates — pushing the
 * win rate down for doing the thing that wins work. That is the first test, and the one that would fail
 * loudest if somebody "simplified" the query.
 *
 * **Keeping it honest:** an open quote is not a loss; an expired one is; and accepted-but-not-invoiced is
 * counted and named, because it is revenue the company has agreed and never asked for.
 *
 * `CrmQuotationAndHandoffTest` owns quote arithmetic, sending rules and the handoff to an invoice. None of it
 * is restated here.
 */
class QuotationConversionReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** February of the 2026-2027 fiscal year, so the calendar year differs from the financial one. */
    private const AS_OF = '2027-02-20';

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['invoicing', 'quotations'] as $module) {
            $this->setModule($module, true);
        }

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );

        $this->customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    /**
     * A quote in a given state.
     *
     * `valid_until` defaults well past the report date, so a test that does not care about expiry does not
     * accidentally create an expired quote — expiry is computed from that column, so a careless default
     * would silently turn every fixture into a loss.
     */
    private function quote(string $status, string $issued = '2026-08-10', array $attributes = []): Quotation
    {
        return Quotation::create(array_merge([
            'contact_id' => $this->customer->id,
            'issue_date' => $issued,
            'valid_until' => '2027-06-01',
            'status' => $status,
            'total' => 100_000,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('QuotationConversion', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for QuotationConversion');

        return $payload;
    }

    private function cell(array $payload, string $month, string $column): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "no [{$column}] column");

        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $month)) {
                return $row[$index];
            }
        }

        $this->fail("no row for [{$month}] in ".collect($payload['rows'])->flatten()->implode(' | '));
    }

    private function footer(array $payload, string $column): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "no [{$column}] column");

        return $payload['footer'][$index];
    }

    // ───────────────────────────────────────────── superseded versions ──

    /**
     * A superseded version is not a quote that was issued.
     *
     * The rule the report exists for. Three revisions of one opportunity, two of them superseded: issued is
     * one, and the win rate is about that one opportunity rather than about how much negotiating it took.
     */
    public function test_superseded_versions_are_excluded_from_every_figure(): void
    {
        $first = $this->quote(Quotation::STATUS_SUPERSEDED, '2026-08-10', ['version' => 1]);
        $second = $this->quote(Quotation::STATUS_SUPERSEDED, '2026-08-12', ['version' => 2, 'supersedes_id' => $first->id]);
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-15', [
            'version' => 3,
            'supersedes_id' => $second->id,
            'accepted_at' => '2026-08-16 09:00:00',
        ]);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Issued'), 'one opportunity, not three');
        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Accepted'));
        $this->assertSame('100%', $this->cell($payload, 'August 2026', 'Win rate'));
        $this->assertStringContainsString('SUPERSEDED VERSIONS EXCLUDED', $payload['note']);
    }

    // ──────────────────────────────────────────────────── the win rate ──

    /**
     * An open quote is not a loss; an expired one is.
     *
     * One accepted, one declined, one expired, one still live. The honest rate is one of three decided —
     * counting the live quote would report 25% and describe a company that is losing work it has not yet
     * been answered on.
     */
    public function test_the_win_rate_counts_decided_quotes_only(): void
    {
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-10', ['accepted_at' => '2026-08-11 09:00:00']);
        $this->quote(Quotation::STATUS_DECLINED, '2026-08-11');
        // Sent and long past its validity: expired, and therefore lost.
        $this->quote(Quotation::STATUS_SENT, '2026-08-12', ['valid_until' => '2026-09-01']);
        // Sent and still live.
        $this->quote(Quotation::STATUS_SENT, '2026-08-13', ['valid_until' => '2027-06-01']);

        $payload = $this->report();

        $this->assertSame('4', $this->cell($payload, 'August 2026', 'Issued'));
        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Expired'));
        $this->assertSame('33%', $this->cell($payload, 'August 2026', 'Win rate'), 'one of three decided');
    }

    /** Nothing decided is a dash, not nought per cent. */
    public function test_a_month_with_nothing_decided_has_no_rate(): void
    {
        $this->quote(Quotation::STATUS_SENT, '2026-08-10', ['valid_until' => '2027-06-01']);

        $this->assertSame('—', $this->cell($this->report(), 'August 2026', 'Win rate'));
    }

    /**
     * Expiry is computed, not only read from the status.
     *
     * The nightly sweep is what sets the status, so between a quote lapsing and the sweep running the stored
     * status still says `sent`. An expired quote must not be acceptable in the meantime, and it must not be
     * counted as still winnable here either.
     */
    public function test_a_lapsed_quote_counts_as_expired_before_the_sweep_runs(): void
    {
        $this->quote(Quotation::STATUS_SENT, '2026-08-10', ['valid_until' => '2026-08-20']);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Expired'));
        $this->assertSame('0%', $this->cell($payload, 'August 2026', 'Win rate'));
    }

    // ────────────────────────────────────────── accepted but not invoiced ──

    /**
     * The second conversion, and the one nothing else surfaces.
     *
     * An accepted quote with no invoice against it is revenue the company has agreed and never asked for, so
     * it is counted, put in its own column and named in the note.
     */
    public function test_accepted_quotes_with_no_invoice_are_counted_and_named(): void
    {
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-10', ['accepted_at' => '2026-08-11 09:00:00', 'invoice_id' => null]);
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-12', ['accepted_at' => '2026-08-13 09:00:00', 'invoice_id' => null]);

        $payload = $this->report();

        $this->assertSame('2', $this->cell($payload, 'August 2026', 'Accepted'));
        $this->assertSame('0', $this->cell($payload, 'August 2026', 'Invoiced'));
        $this->assertStringContainsString('2 ACCEPTED QUOTES HAVE NO INVOICE YET', $payload['note']);
    }

    /** And a fully billed month makes no such claim. */
    public function test_a_fully_invoiced_month_is_not_reported_as_unbilled(): void
    {
        // `kind` is required — the model numbers an invoice from it on create, and a null throws before the
        // row is written.
        $invoice = Invoice::create([
            'contact_id' => $this->customer->id,
            'kind' => Invoice::KIND_SALE,
            'invoice_date' => '2026-08-20',
            'total' => 100_000,
        ]);

        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-10', [
            'accepted_at' => '2026-08-11 09:00:00',
            'invoice_id' => $invoice->id,
        ]);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Invoiced'));
        $this->assertStringNotContainsString('NO INVOICE YET', $payload['note']);
    }

    // ────────────────────────────────────────────────────── expiring soon ──

    /** A live quote running out inside the window is flagged on its own month. */
    public function test_a_quote_expiring_soon_is_flagged(): void
    {
        // Read on 20 February; this runs out on the 25th.
        $this->quote(Quotation::STATUS_SENT, '2027-02-01', ['valid_until' => '2027-02-25']);

        $payload = $this->report();

        $this->assertSame('1', $this->cell($payload, 'February 2027', 'Expiring'));
        $this->assertStringContainsString('1 EXPIRING WITHIN 14 DAYS', $payload['note']);
    }

    /** A quote that has already lapsed is expired, not expiring. */
    public function test_an_already_lapsed_quote_is_not_counted_as_expiring(): void
    {
        $this->quote(Quotation::STATUS_SENT, '2026-08-10', ['valid_until' => '2026-08-20']);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'August 2026', 'Expiring'));
        $this->assertSame('1', $this->cell($payload, 'August 2026', 'Expired'));
    }

    /** A draft is not expiring, because nobody has been given it. */
    public function test_a_draft_is_not_counted_as_expiring(): void
    {
        $this->quote(Quotation::STATUS_DRAFT, '2027-02-01', ['valid_until' => '2027-02-25']);

        $this->assertSame('—', $this->cell($this->report(), 'February 2027', 'Expiring'));
    }

    /** And one running out well beyond the window is not flagged. */
    public function test_a_quote_expiring_later_is_not_flagged(): void
    {
        $this->quote(Quotation::STATUS_SENT, '2027-02-01', ['valid_until' => '2027-05-01']);

        $this->assertSame('—', $this->cell($this->report(), 'February 2027', 'Expiring'));
    }

    // ─────────────────────────────────────────────────────── the period ──

    /**
     * The financial year, not the calendar year.
     *
     * Read in February, a calendar-year window would start on 1 January and drop the first seven months of
     * the company's quoting.
     */
    public function test_the_period_is_the_financial_year(): void
    {
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-10', ['accepted_at' => '2026-08-11 09:00:00']);
        // Before the fiscal year began.
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-06-10', ['accepted_at' => '2026-06-11 09:00:00']);

        $payload = $this->report();

        $this->assertStringContainsString('2026-07-01', $payload['subtitle']);
        $this->assertCount(1, $payload['rows'], 'only August is in the year');
    }

    /** Months are rows, in order. */
    public function test_months_are_rows_in_order(): void
    {
        $this->quote(Quotation::STATUS_SENT, '2026-09-10', ['valid_until' => '2027-06-01']);
        $this->quote(Quotation::STATUS_SENT, '2026-08-10', ['valid_until' => '2027-06-01']);

        $payload = $this->report();

        $this->assertStringContainsString('August 2026', $payload['rows'][0][0]);
        $this->assertStringContainsString('September 2026', $payload['rows'][1][0]);
    }

    /** The record row foots the months above it. */
    public function test_the_record_row_foots_the_months(): void
    {
        $this->quote(Quotation::STATUS_ACCEPTED, '2026-08-10', ['accepted_at' => '2026-08-11 09:00:00']);
        $this->quote(Quotation::STATUS_DECLINED, '2026-09-10');

        $payload = $this->report();

        $this->assertSame('2', $this->footer($payload, 'Issued'));
        $this->assertSame('1', $this->footer($payload, 'Accepted'));
        $this->assertSame('50%', $this->footer($payload, 'Win rate'));
    }

    /** No quote is a sentence, not an empty grid. */
    public function test_it_says_when_nothing_was_quoted(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO QUOTATION WAS ISSUED IN THIS PERIOD', $payload['note']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->quote(Quotation::STATUS_SENT, '2026-08-10', ['valid_until' => '2027-06-01']);

        $onThePage = Livewire::test(QuotationConversion::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('QuotationConversion', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(QuotationConversion::canAccess());
    }
}
