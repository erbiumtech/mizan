<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Services\LeadConversion;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Leads and conversion — docs/crms-plan.md §1, §10 and the §12 cases reaching phase 1.
 *
 * Two things are being defended here, and they are the two the plan spends the most
 * words on:
 *
 *  1. **`crm` requires nothing.** Leads work with Invoicing unlicensed; conversion is
 *     absent rather than broken. If somebody ever declares the requirement to make an
 *     import easier, these tests fail.
 *  2. **Intentions must not write to the record without a person in between** (§10).
 *     Converting a lead creates a customer and nothing else — no invoice, no journal
 *     entry. This is the rule six of §10's seven prohibitions come from.
 */
class CrmLeadTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();
    }

    private function makeLead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'company_name' => 'Karachi Textiles',
            'person_name' => 'Ayesha Khan',
            'email' => 'ayesha@karachitextiles.test',
            'phone' => '+92 300 1234567',
        ], $attributes));
    }

    /**
     * Keyed on the Filament tenant rather than Company::current().
     *
     * Modules::currentCompanyId() reads the panel's tenant first and only falls back to
     * Company::current(), because in this suite the former is set and the latter is
     * null — the comment on that method records that reading only one of them made
     * every gate fail open. InteractsWithTenant sets the panel tenant, so that is the
     * company these rows have to belong to.
     */
    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    // ---------------------------------------------------------------- the lead itself

    /**
     * A lead has to be somebody.
     *
     * Enforced on the model rather than in the form, because the capture endpoint and
     * the spreadsheet import of later phases are both unattended writers — and a row
     * with neither a company nor a person is exactly what an open endpoint fills a
     * table with.
     */
    public function test_a_lead_needs_a_company_or_a_person(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a company or a person');

        Lead::create(['email' => 'nobody@example.test']);
    }

    public function test_either_half_of_the_identity_alone_is_enough(): void
    {
        $companyOnly = $this->makeLead(['person_name' => null]);
        $personOnly = $this->makeLead(['company_name' => null]);

        $this->assertSame('Karachi Textiles', $companyOnly->display_label);
        $this->assertSame('Ayesha Khan', $personOnly->display_label);
    }

    /** Whoever typed it, recorded — and the ownership fallback when `employees` is off. */
    public function test_the_creator_is_stamped(): void
    {
        $this->assertSame($this->actor->id, $this->makeLead()->created_by);
    }

    public function test_a_new_lead_is_open(): void
    {
        $lead = $this->makeLead();

        $this->assertSame(Lead::STATUS_NEW, $lead->status);
        $this->assertTrue($lead->isOpen());
        $this->assertSame(1, Lead::open()->count());
    }

    // ---------------------------------------------------------------- conversion

    /**
     * §12.2 — THE test for conversion.
     *
     * A Contact is created, the link is recorded, and the lead stays readable as the
     * origin. That last part is the one worth being explicit about: the lead is not
     * consumed by conversion, because "where did this customer come from" is a
     * lead-source question asked years later and it is the only reason win rate by
     * source can be worked out.
     */
    public function test_converting_creates_a_contact_and_keeps_the_lead_as_the_origin(): void
    {
        $source = LeadSource::create(['name' => 'Referral']);
        $lead = $this->makeLead(['lead_source_id' => $source->id]);

        $contact = app(LeadConversion::class)->convert($lead);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('Karachi Textiles', $contact->name);
        $this->assertSame(Contact::KIND_CUSTOMER, $contact->kind);
        $this->assertSame('ayesha@karachitextiles.test', $contact->email);

        // Deliberately not invented. Contact::TERMS leaves null as its own option
        // because "none agreed" and "due on receipt" are different facts, and made-up
        // terms would put a brand-new customer into the overdue bucket on day one.
        $this->assertNull($contact->payment_terms_days);

        $lead->refresh();
        $this->assertSame(Lead::STATUS_CONVERTED, $lead->status);
        $this->assertSame($contact->id, $lead->converted_contact_id);
        $this->assertNotNull($lead->converted_at);

        // Still there, still answering where the customer came from.
        $this->assertSame('Referral', $lead->fresh()->source->name);
        $this->assertSame($contact->id, $lead->convertedContact->id);
    }

    /** A sole trader is both halves, so the person's name becomes the customer. */
    public function test_a_lead_with_only_a_person_converts_under_their_name(): void
    {
        $lead = $this->makeLead(['company_name' => null]);

        $this->assertSame('Ayesha Khan', app(LeadConversion::class)->convert($lead)->name);
    }

    /**
     * §12.4, adapted to this phase — the §10 rule, asserted rather than assumed.
     *
     * Converting a lead is a sales fact. It must not raise an invoice and must not post
     * a journal entry: an invoice is a legal document, and since FBR digital invoicing
     * a transmitted one cannot be freely voided after 72 hours. A button that created
     * one would be creating something nobody can take back.
     */
    public function test_converting_raises_no_invoice_and_posts_nothing(): void
    {
        $invoicesBefore = Invoice::count();
        $entriesBefore = JournalEntry::count();

        app(LeadConversion::class)->convert($this->makeLead());

        $this->assertSame($invoicesBefore, Invoice::count(), 'Conversion must not raise an invoice.');
        $this->assertSame($entriesBefore, JournalEntry::count(), 'Conversion must not post to the ledger.');
    }

    /**
     * Refused rather than silently idempotent.
     *
     * Converting twice would create a second customer for one prospect, and the second
     * is the one nobody notices until an invoice goes to the wrong record.
     */
    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $lead = $this->makeLead();
        app(LeadConversion::class)->convert($lead);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been converted');

        app(LeadConversion::class)->convert($lead->fresh());
    }

    /** The conversion is recorded, with what it became. */
    public function test_conversion_is_written_to_the_activity_log(): void
    {
        $lead = $this->makeLead();
        $contact = app(LeadConversion::class)->convert($lead);

        $activity = \Spatie\Activitylog\Models\Activity::where('log_name', 'Lead')
            ->where('event', 'converted')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($contact->id, $activity->properties['contact_id']);
    }

    // ---------------------------------------------------------------- lost and reopened

    public function test_marking_a_lead_lost_needs_a_reason(): void
    {
        $lead = $this->makeLead();

        try {
            app(LeadConversion::class)->markLost($lead, '  ');
            $this->fail('A blank reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        app(LeadConversion::class)->markLost($lead, 'Went with a cheaper supplier');

        $lead->refresh();
        $this->assertSame(Lead::STATUS_LOST, $lead->status);
        $this->assertSame('Went with a cheaper supplier', $lead->lost_reason);
        $this->assertNotNull($lead->lost_at);
        $this->assertFalse($lead->isOpen());
    }

    /**
     * Reopening clears the loss, so a lead cannot be both lost and converted.
     *
     * That matters for win/loss: a deal counted as lost *and* won would be counted
     * twice, in opposite directions.
     */
    public function test_a_lost_lead_can_be_reopened_and_then_converted(): void
    {
        $lead = $this->makeLead();
        app(LeadConversion::class)->markLost($lead, 'Budget frozen');

        // Not straight from lost: the record should say the deal came back.
        try {
            app(LeadConversion::class)->convert($lead->fresh());
            $this->fail('A lost lead should be reopened before it is converted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('marked lost', $e->getMessage());
        }

        app(LeadConversion::class)->reopen($lead);
        $lead->refresh();

        $this->assertSame(Lead::STATUS_WORKING, $lead->status);
        $this->assertNull($lead->lost_reason);
        $this->assertNull($lead->lost_at);

        app(LeadConversion::class)->convert($lead);

        $lead->refresh();
        $this->assertSame(Lead::STATUS_CONVERTED, $lead->status);
        $this->assertNull($lead->lost_at, 'Converting must clear the loss, or win/loss counts it both ways.');
    }

    public function test_a_converted_lead_cannot_be_marked_lost(): void
    {
        $lead = $this->makeLead();
        app(LeadConversion::class)->convert($lead);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been converted');

        app(LeadConversion::class)->markLost($lead->fresh(), 'Changed their mind');
    }

    // ---------------------------------------------------------------- degradation

    /**
     * §12.3 — the whole bet of §1, asserted.
     *
     * With `invoicing` unlicensed, leads work completely and conversion is simply not
     * offered. If anybody ever adds `'requires' => ['invoicing']` to make something
     * easier, this test is what says no.
     */
    public function test_leads_work_with_invoicing_unlicensed_and_conversion_is_not_offered(): void
    {
        $this->setModule('crm', true);
        $this->setModule('invoicing', false);

        // Everything about working a lead still works.
        $lead = $this->makeLead();
        $lead->update(['status' => Lead::STATUS_QUALIFIED, 'rating' => Lead::RATING_HOT]);
        $this->assertSame(Lead::STATUS_QUALIFIED, $lead->fresh()->status);

        app(LeadConversion::class)->markLost($lead, 'No budget');
        app(LeadConversion::class)->reopen($lead);
        $this->assertTrue($lead->fresh()->isOpen());

        // Conversion is absent rather than broken.
        $this->assertFalse(app(LeadConversion::class)->isAvailable());

        // A caller that skipped the guard gets a loud failure, not a half-made
        // customer: no wording on a form fixes an unlicensed module.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without the Invoicing module');

        app(LeadConversion::class)->convert($lead->fresh());
    }

    /** And with Invoicing on, the same guard reports the opposite. */
    public function test_conversion_is_available_once_invoicing_is_licensed(): void
    {
        $this->setModule('crm', true);
        $this->setModule('accounting', true);
        $this->setModule('invoicing', true);

        $this->assertTrue(app(LeadConversion::class)->isAvailable());
    }

    /**
     * Ownership is an employee, so EmployeeAccess scoping applies unchanged — and with
     * `employees` unlicensed the owner is simply never set, with `created_by`
     * answering instead. Neither case is an error.
     */
    public function test_ownership_is_an_employee_and_optional(): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-SALES',
            'name' => 'Sales Lead',
            'gender' => 'Female',
            'is_active' => true,
        ]);

        $owned = $this->makeLead(['owner_employee_id' => $employee->id]);
        $this->assertSame($employee->id, $owned->owner->id);

        $unowned = $this->makeLead(['company_name' => 'Lahore Mills']);
        $this->assertNull($unowned->owner);
        $this->assertSame($this->actor->id, $unowned->created_by);
    }

    /** A source keeps its leads, which is what makes win-rate-by-source possible. */
    public function test_a_source_counts_its_leads(): void
    {
        $source = LeadSource::create(['name' => 'Event or expo']);
        $this->makeLead(['lead_source_id' => $source->id]);
        $this->makeLead(['company_name' => 'Multan Traders', 'lead_source_id' => $source->id]);

        $this->assertSame(2, $source->leads()->count());
    }
}
