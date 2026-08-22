<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Services\LeadDeduplication;
use App\Modules\Invoicing\Models\Contact;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 3.5: the public capture endpoint and deduplication — docs/crms-plan.md §3 and §12.15
 * through §12.18.
 *
 * This is the only unauthenticated write surface the CRM has, so the tests are about the
 * four constraints §3 names, each of which is a way it becomes a spam sink otherwise:
 *
 *  1. **Gated twice** — the token AND a per-company setting. §12.15 requires BOTH to be
 *     tested, because a single gate cannot be closed without a deploy.
 *  2. **Throttled silently** — §12.17. An endpoint that answers 429 tells a bot how to pace
 *     itself.
 *  3. **A fixed field list.** A payload nobody validates becomes a column nobody can query.
 *  4. **It never converts** — §12.16. §10's rule, at the one door where breaking it would be
 *     most tempting.
 */
class CrmLeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private const TOKEN = 'a-long-shared-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as StatusPageTest does.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
            \App\Multitenancy\Tasks\SwitchTenantFilesystemTask::class,
        ]]);

        $this->company = Company::factory()->create(['slug' => 'acme']);
        $this->company->makeCurrent();

        $this->setModule('crm', true);

        LeadSource::create(['name' => 'Web form']);

        RateLimiter::clear('lead-capture:'.$this->company->getKey());
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        parent::tearDown();
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function openTheGates(): void
    {
        app(TenantSettings::class)->set('crm.lead_capture.enabled', true);
        app(TenantSettings::class)->set('crm.lead_capture.token', self::TOKEN);
    }

    /** Named `capture` rather than `post`: the framework's TestCase already has post(). */
    private function capture(array $payload = [], ?string $token = null)
    {
        // Forgotten deliberately before each request: a real request arrives with no tenant
        // current, and leaving one would let the test pass on state the middleware is
        // supposed to establish.
        Company::forgetCurrent();

        $response = $this->postJson(
            "/leads/{$this->company->slug}/".($token ?? self::TOKEN),
            $payload + ['company_name' => 'Karachi Textiles'],
        );

        $this->company->makeCurrent();

        return $response;
    }

    // ─────────────────────────── §12.15: both gates ───────────────────────────

    /**
     * THE §12.15 test: a valid token with the setting off creates nothing, and an invalid
     * token with the setting on creates nothing. **Both must be true.**
     *
     * The point of two gates is that a leaked token can be closed by switching the setting
     * off, without a deploy.
     */
    public function test_a_valid_token_with_capture_switched_off_creates_nothing(): void
    {
        app(TenantSettings::class)->set('crm.lead_capture.enabled', false);
        app(TenantSettings::class)->set('crm.lead_capture.token', self::TOKEN);

        $this->capture()->assertNotFound();

        $this->assertSame(0, Lead::count());
    }

    public function test_an_invalid_token_with_capture_switched_on_creates_nothing(): void
    {
        $this->openTheGates();

        $this->capture(token: 'not-the-token')->assertNotFound();

        $this->assertSame(0, Lead::count());
    }

    public function test_both_gates_open_captures_the_lead(): void
    {
        $this->openTheGates();

        $this->capture(['person_name' => 'Ayesha Khan', 'email' => 'ayesha@example.test'])
            ->assertStatus(202);

        $lead = Lead::sole();
        $this->assertSame('Karachi Textiles', $lead->company_name);
        $this->assertSame('ayesha@example.test', $lead->email);
        $this->assertSame('Web form', $lead->source->name);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
    }

    /** A third condition, not a replacement: the module licence is checked too. */
    public function test_an_unlicensed_module_closes_the_endpoint(): void
    {
        $this->openTheGates();
        $this->setModule('crm', false);

        $this->capture()->assertNotFound();

        $this->assertSame(0, Lead::count());
    }

    /** 404 throughout: the endpoint never confirms it exists. */
    public function test_an_unknown_company_is_not_found(): void
    {
        $this->openTheGates();

        Company::forgetCurrent();
        $this->postJson('/leads/no-such-company/'.self::TOKEN, ['company_name' => 'X'])->assertNotFound();
        $this->company->makeCurrent();
    }

    // ─────────────────────────── §12.16: it never converts ────────────────────

    /**
     * THE §12.16 test: a posted payload creates a lead and no Contact, whatever it contains.
     *
     * §10's rule at the one door where breaking it would be most tempting — a payload that
     * looked like a real customer is exactly what would invite an automatic conversion.
     */
    public function test_the_endpoint_never_converts_whatever_the_payload_says(): void
    {
        $this->openTheGates();

        $contactsBefore = Contact::count();

        $this->capture([
            'person_name' => 'Ayesha Khan',
            'email' => 'ayesha@example.test',
            'phone' => '+92 300 1234567',
            // Fields a caller might hope get it promoted. None of them are in the list.
            'status' => Lead::STATUS_CONVERTED,
            'converted_contact_id' => 1,
            'owner_employee_id' => 1,
        ])->assertStatus(202);

        $this->assertSame($contactsBefore, Contact::count(), 'Capture must never create a customer.');

        $lead = Lead::sole();
        $this->assertSame(Lead::STATUS_NEW, $lead->status, 'A posted status must be ignored.');
        $this->assertNull($lead->converted_contact_id);
        $this->assertNull($lead->owner_employee_id);
    }

    /** A fixed field list: anything else in the payload is ignored, not stored. */
    public function test_only_the_listed_fields_are_stored(): void
    {
        $this->openTheGates();

        $this->capture([
            'person_name' => 'Ayesha',
            'city' => 'Karachi',
            'estimated_value' => 999999,
            'rating' => Lead::RATING_HOT,
        ])->assertStatus(202);

        $lead = Lead::sole();
        $this->assertSame('Karachi', $lead->city);
        $this->assertNull($lead->estimated_value);
        $this->assertNull($lead->rating);
    }

    /** A payload naming nobody is accepted and stores nothing — it tells a bot no more. */
    public function test_a_payload_naming_nobody_is_accepted_and_stores_nothing(): void
    {
        $this->openTheGates();

        Company::forgetCurrent();
        $this->postJson("/leads/{$this->company->slug}/".self::TOKEN, ['email' => 'nobody@example.test'])
            ->assertStatus(202);
        $this->company->makeCurrent();

        $this->assertSame(0, Lead::count());
    }

    /** An over-long field is truncated rather than losing the whole lead. */
    public function test_an_overlong_field_is_truncated_rather_than_rejected(): void
    {
        $this->openTheGates();

        $this->capture(['city' => str_repeat('x', 5000)])->assertStatus(202);

        $this->assertSame(255, mb_strlen(Lead::sole()->city));
    }

    // ─────────────────────────── §12.17: silent throttling ────────────────────

    /**
     * THE §12.17 test: a rate-limited request is rejected **without disclosing the limit**.
     *
     * The response to an over-limit caller is indistinguishable from an ordinary one — same
     * 202, no Retry-After, no 429. A bot gets nothing to tune against.
     */
    public function test_throttling_is_silent_and_indistinguishable_from_success(): void
    {
        $this->openTheGates();
        config(['crm.lead_capture.per_minute_per_ip' => 2]);

        // Distinct company names, or dedup would fold them into one lead and the count
        // below would prove nothing about throttling.
        for ($i = 1; $i <= 2; $i++) {
            $this->capture(['company_name' => "Company {$i}", 'email' => "p{$i}@example.test"])
                ->assertStatus(202);
        }

        $throttled = $this->capture(['company_name' => 'Company 3', 'email' => 'third@example.test']);

        // Same status, and nothing that names the limit.
        $throttled->assertStatus(202);
        $throttled->assertJson(['status' => 'accepted']);
        $this->assertNull($throttled->headers->get('Retry-After'));
        $this->assertNull($throttled->headers->get('X-RateLimit-Limit'));

        // And it genuinely wrote nothing.
        $this->assertSame(2, Lead::count());
    }

    // ─────────────────────────── §12.18: deduplication ───────────────────────

    /**
     * Capture folds a repeat submission into the existing lead rather than duplicating it.
     *
     * §13 upgraded dedup from "phase 3.5 at the latest" to shipping WITH the endpoint,
     * because an open door removes the person who would have noticed the duplicate.
     */
    public function test_a_repeat_submission_updates_the_existing_lead(): void
    {
        $this->openTheGates();

        $this->capture(['person_name' => 'Ayesha', 'email' => 'ayesha@example.test'])->assertStatus(202);

        // Same email, and this time a phone number: new information on the same person.
        $this->capture(['person_name' => 'Ayesha', 'email' => 'ayesha@example.test', 'phone' => '+92 300 1234567'])
            ->assertStatus(202);

        $this->assertSame(1, Lead::count(), 'A repeat submission must not create a second lead.');
        $this->assertSame('+92 300 1234567', Lead::sole()->phone);
    }

    /**
     * §12.18 — a merge **keeps the older record's id** and moves its history onto it.
     *
     * Asserted by counting activities before and after, because the failure mode is losing
     * history rather than losing the duplicate: a merge that dropped a call log would look
     * successful and leave the survivor's timeline short.
     */
    public function test_a_merge_keeps_the_older_id_and_moves_the_history(): void
    {
        $older = Lead::create(['company_name' => 'Karachi Textiles', 'email' => 'info@kt.test']);
        $newer = Lead::create(['company_name' => 'Karachi Textiles', 'phone' => '+92 300 1234567']);

        // timeline(), not activities(): the latter is spatie's audit trail on any Auditable
        // model, and reading it here is what surfaced the shadowing bug.
        $older->timeline()->create(['kind' => Activity::KIND_CALL]);
        $newer->timeline()->create(['kind' => Activity::KIND_MEETING]);
        $newer->nextActions()->create([
            'title' => 'Call back', 'due_on' => now()->addDay()->toDateString(),
        ]);

        $activitiesBefore = Activity::count();

        $kept = app(LeadDeduplication::class)->merge($older, $newer);

        $this->assertSame($older->id, $kept->id, 'The older id survives — everything else points at it.');
        $this->assertSame(1, Lead::count());

        // Nothing lost.
        $this->assertSame($activitiesBefore, Activity::count(), 'A merge must not lose history.');
        $this->assertSame(2, $kept->timeline()->count());
        $this->assertSame(1, $kept->nextActions()->count());

        // Blanks filled from the duplicate: the phone number is new information.
        $this->assertSame('+92 300 1234567', $kept->phone);
        $this->assertSame('info@kt.test', $kept->email);
    }

    /** Passing the merge the wrong way round still keeps the older record. */
    public function test_a_merge_cannot_be_run_the_wrong_way_round(): void
    {
        $older = Lead::create(['company_name' => 'Karachi Textiles', 'email' => 'info@kt.test']);
        $newer = Lead::create(['company_name' => 'Karachi Textiles']);

        $kept = app(LeadDeduplication::class)->merge($newer, $older);

        $this->assertSame($older->id, $kept->id);
    }

    /** Phone numbers match on digits: "+92 300 1234567" and "03001234567" are one number. */
    public function test_candidates_match_on_digits_rather_than_formatting(): void
    {
        Lead::create(['person_name' => 'Ayesha', 'phone' => '+92 300 1234567']);

        $candidates = app(LeadDeduplication::class)->candidatesFor(
            new Lead(['person_name' => 'Ayesha', 'phone' => '0092-300-1234567'])
        );

        $this->assertCount(1, $candidates);
    }

    /** A lead with nothing to match on is nobody's duplicate. */
    public function test_a_lead_with_nothing_to_match_on_finds_no_candidates(): void
    {
        Lead::create(['person_name' => 'Ali']);

        $this->assertCount(
            0,
            app(LeadDeduplication::class)->candidatesFor(new Lead(['person_name' => 'Ali'])),
            'Two people called Ali are not the same person.',
        );
    }

    /** A converted lead is a customer's origin record and cannot be merged away. */
    public function test_a_converted_lead_cannot_be_merged_away(): void
    {
        $this->setModule('accounting', true);
        $this->setModule('invoicing', true);

        $older = Lead::create(['company_name' => 'Karachi Textiles', 'email' => 'info@kt.test']);
        $newer = Lead::create(['company_name' => 'Karachi Textiles']);

        app(\App\Modules\Crm\Services\LeadConversion::class)->convert($newer);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already become a customer');

        app(LeadDeduplication::class)->merge($older, $newer->fresh());
    }
}
