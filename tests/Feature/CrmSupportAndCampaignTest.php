<?php

namespace Tests\Feature;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Services\CampaignSender;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Modules\Support\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * CRM phases 8 and 9 — docs/crms-plan.md §5, §6, and §12.11 through §12.13.
 *
 * Four assertions carry this file, and every one of them is a refusal:
 *
 *  - **An SLA breach is reported and does not block closing** (§12.12).
 *  - **An internal reply never starts the response clock** — otherwise a company meets its
 *    commitment by writing to itself.
 *  - **`is_internal` replies are never returned by a customer-facing query** (§12.13) — a guard
 *    written in advance of the portal it guards.
 *  - **A send to somebody with revoked or absent consent does not go out** (§12.11). No record
 *    means no: silence is not consent.
 */
class CrmSupportAndCampaignTest extends TestCase
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

        foreach (['crm', 'support', 'campaigns', 'accounting', 'invoicing'] as $module) {
            $this->setModule($module, true);
        }
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    // ═══════════════════════════ support ══════════════════════════════════════

    private function makeTicket(array $attributes = [], ?int $responseSla = 60, ?int $resolutionSla = 480): Ticket
    {
        $category = TicketCategory::create([
            'name' => 'General '.uniqid(),
            'sla_response_minutes' => $responseSla,
            'sla_resolution_minutes' => $resolutionSla,
        ]);

        return Ticket::create(array_merge([
            'category_id' => $category->id,
            'subject' => 'The reader is offline',
            'opened_at' => now()->subMinutes(30),
        ], $attributes));
    }

    /**
     * §12.12 — a breach is reported and does **not** block closing.
     *
     * The elapsed time is a fact about the past. Refusing to record that the problem was fixed
     * would make the register wrong as well as late.
     */
    public function test_an_sla_breach_is_reported_and_does_not_block_closing(): void
    {
        // Opened three hours ago against a one-hour response commitment: already breached, and
        // nobody has answered.
        $ticket = $this->makeTicket(['opened_at' => now()->subHours(3)]);

        $this->assertTrue($ticket->hasBreachedResponse(), 'An unanswered ticket past its time is breached.');
        $this->assertStringContainsString('reported, not enforced', $ticket->slaNote());

        // And it still closes.
        app(TicketService::class)->close($ticket);

        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->resolved_at);
    }

    /** A category with no commitment is not a breach waiting to happen. */
    public function test_a_category_with_no_sla_never_breaches(): void
    {
        // BOTH commitments null: a category with no SLA at all. Nulling only the response one
        // would leave the resolution clock running, which is what this fixture got wrong first.
        $ticket = $this->makeTicket(
            ['opened_at' => now()->subYear()],
            responseSla: null,
            resolutionSla: null,
        );

        $this->assertFalse($ticket->hasBreachedResponse());
        $this->assertNull($ticket->slaNote());
    }

    /**
     * **An internal note does not start the response clock.**
     *
     * Otherwise a company could meet its commitment by writing a note to itself — the exact
     * failure a measured SLA exists to make visible.
     */
    public function test_an_internal_note_does_not_start_the_response_clock(): void
    {
        $ticket = $this->makeTicket();

        app(TicketService::class)->reply($ticket, 'Looks like the router again.', $this->actor, internal: true);

        $this->assertNull($ticket->fresh()->first_responded_at, 'A note to ourselves is not a response.');
        $this->assertSame(Ticket::STATUS_NEW, $ticket->fresh()->status);

        app(TicketService::class)->reply($ticket->fresh(), 'We are looking into it.', $this->actor);

        $this->assertNotNull($ticket->fresh()->first_responded_at);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
    }

    /**
     * §12.13 — internal replies are **never** returned by a customer-facing query.
     *
     * A guard written before the surface it guards: §7 defers the client portal, and this is
     * what makes the deferral safe to reverse later.
     */
    public function test_internal_replies_are_never_customer_visible(): void
    {
        $ticket = $this->makeTicket();

        app(TicketService::class)->reply($ticket, 'We are looking into it.', $this->actor);
        app(TicketService::class)->reply($ticket->fresh(), 'The customer broke it themselves.', $this->actor, internal: true);

        $this->assertSame(2, $ticket->fresh()->replies()->count());
        $this->assertSame(1, $ticket->fresh()->customerVisibleReplies()->count());
        $this->assertStringNotContainsString(
            'broke it themselves',
            $ticket->fresh()->customerVisibleReplies()->first()->body,
        );
    }

    /** The first response is kept through a reopen: that is what the customer experienced. */
    public function test_reopening_keeps_the_original_response_time_and_counts(): void
    {
        $ticket = $this->makeTicket();
        app(TicketService::class)->reply($ticket, 'On it.', $this->actor);
        $firstResponse = $ticket->fresh()->first_responded_at;

        app(TicketService::class)->resolve($ticket->fresh());
        app(TicketService::class)->reopen($ticket->fresh());

        $this->assertSame(
            $firstResponse->toDateTimeString(),
            $ticket->fresh()->first_responded_at->toDateTimeString(),
        );
        $this->assertSame(1, $ticket->fresh()->reopened_count);
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TicketService::class)->reply($this->makeTicket(), '   ', $this->actor);
    }

    /** Breaches are reported as a list somebody can act on. */
    public function test_breaches_are_reported_for_open_tickets(): void
    {
        $this->makeTicket(['opened_at' => now()->subHours(5)]);
        $this->makeTicket(['opened_at' => now()->subMinutes(5)]);

        $this->assertCount(1, app(TicketService::class)->breaches());
    }

    // ═══════════════════════════ campaigns ════════════════════════════════════

    private function makeSegment(): Segment
    {
        return Segment::create([
            'name' => 'All leads',
            'definition' => ['include_leads' => true, 'include_contacts' => false],
        ]);
    }

    private function makeCampaign(array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'name' => 'Spring outreach',
            'channel' => Campaign::CHANNEL_EMAIL,
            'subject' => 'A quick note',
            'segment_id' => $this->makeSegment()->id,
        ], $attributes));
    }

    /**
     * §12.11 — THE consent test. A send to somebody without consent does not go out.
     *
     * **No record means no.** Silence is not agreement, and a prospect nobody has asked has not
     * agreed to anything.
     */
    public function test_a_recipient_without_consent_is_skipped_not_sent(): void
    {
        $lead = Lead::create(['company_name' => 'No Consent Ltd', 'email' => 'nc@example.test']);
        $campaign = $this->makeCampaign();

        $result = app(CampaignSender::class)->prepare($campaign);

        $this->assertSame(0, $result['permitted']);
        $this->assertSame(1, $result['skipped']);

        $send = CampaignSend::sole();
        $this->assertSame(CampaignSend::STATUS_SKIPPED_NO_CONSENT, $send->status);
        $this->assertStringContainsString('Silence is not consent', $send->failed_reason);
    }

    public function test_a_recipient_with_consent_is_sent_to(): void
    {
        $lead = Lead::create(['company_name' => 'Willing Ltd', 'email' => 'yes@example.test']);
        Consent::grant($lead, Consent::CHANNEL_EMAIL, 'signed up on the website');

        $campaign = $this->makeCampaign();
        app(CampaignSender::class)->prepare($campaign);

        $result = app(CampaignSender::class)->send($campaign->fresh());

        $this->assertSame(1, $result['sent']);
        $this->assertSame(CampaignSend::STATUS_SENT, CampaignSend::sole()->status);
    }

    /**
     * Consent is **re-checked at send time**, not trusted from the audience check.
     *
     * The gap between preparing a campaign and sending it is exactly where a complaint comes
     * from: the message that went out after somebody asked you to stop.
     */
    public function test_consent_withdrawn_after_preparing_stops_the_send(): void
    {
        $lead = Lead::create(['company_name' => 'Changed Mind Ltd', 'email' => 'stop@example.test']);
        Consent::grant($lead, Consent::CHANNEL_EMAIL);

        $campaign = $this->makeCampaign();
        app(CampaignSender::class)->prepare($campaign);
        $this->assertSame(CampaignSend::STATUS_PENDING, CampaignSend::sole()->status);

        // They unsubscribe in between.
        Consent::revoke($lead, Consent::CHANNEL_EMAIL, 'replied STOP');

        $result = app(CampaignSender::class)->send($campaign->fresh());

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('withdrawn', CampaignSend::sole()->failed_reason);
    }

    /**
     * Consent is a **history**, not a flag: opting in, out and in again leaves three rows and
     * the latest wins.
     *
     * A boolean could not answer "who agreed to this, and when" — which is the only question
     * that matters when somebody complains.
     */
    public function test_consent_is_a_history_and_the_latest_row_decides(): void
    {
        $lead = Lead::create(['company_name' => 'Fickle Ltd', 'email' => 'f@example.test']);

        Consent::grant($lead, Consent::CHANNEL_EMAIL, 'website form');
        $this->assertTrue(Consent::permits($lead, Consent::CHANNEL_EMAIL));

        Consent::revoke($lead, Consent::CHANNEL_EMAIL, 'replied STOP');
        $this->assertFalse(Consent::permits($lead, Consent::CHANNEL_EMAIL));

        Consent::grant($lead, Consent::CHANNEL_EMAIL, 'asked to be re-added');
        $this->assertTrue(Consent::permits($lead, Consent::CHANNEL_EMAIL));

        // All three survive. The history is the evidence.
        $this->assertSame(3, Consent::count());
        $this->assertSame('website form', Consent::orderBy('id')->first()->source);
    }

    /** Consent is per channel: agreeing to email is not agreeing to WhatsApp. */
    public function test_consent_is_per_channel(): void
    {
        $lead = Lead::create(['company_name' => 'Email Only Ltd', 'email' => 'e@example.test']);
        Consent::grant($lead, Consent::CHANNEL_EMAIL);

        $this->assertTrue(Consent::permits($lead, Consent::CHANNEL_EMAIL));
        $this->assertFalse(Consent::permits($lead, Consent::CHANNEL_WHATSAPP));
    }

    /**
     * A WhatsApp campaign **cannot be saved without an approved template**.
     *
     * Meta refuses free text outside a 24-hour service window, and repeated attempts risk the
     * number — so this is asserted on the model rather than only in the form.
     */
    public function test_a_whatsapp_campaign_needs_an_approved_template(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pre-approved template');

        Campaign::create([
            'name' => 'Risky blast',
            'channel' => Campaign::CHANNEL_WHATSAPP,
            'body' => 'Hi, we have a special offer!',
            'segment_id' => $this->makeSegment()->id,
        ]);
    }

    public function test_a_whatsapp_campaign_with_a_template_is_accepted(): void
    {
        $campaign = Campaign::create([
            'name' => 'Approved blast',
            'channel' => Campaign::CHANNEL_WHATSAPP,
            'template_name' => 'seasonal_offer_v2',
            'segment_id' => $this->makeSegment()->id,
        ]);

        $this->assertTrue($campaign->isWhatsApp());
        $this->assertSame('seasonal_offer_v2', $campaign->template_name);
    }

    public function test_an_email_campaign_needs_a_subject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a subject');

        Campaign::create([
            'name' => 'No subject',
            'channel' => Campaign::CHANNEL_EMAIL,
            'segment_id' => $this->makeSegment()->id,
        ]);
    }

    /** WhatsApp uses the dedicated number where there is one: it is often not the card number. */
    public function test_a_whatsapp_campaign_prefers_the_whatsapp_number(): void
    {
        $lead = Lead::create([
            'company_name' => 'Two Numbers Ltd',
            'phone' => '+92 300 1111111',
            'whatsapp' => '+92 301 2222222',
        ]);
        Consent::grant($lead, Consent::CHANNEL_WHATSAPP);

        $campaign = Campaign::create([
            'name' => 'Approved blast',
            'channel' => Campaign::CHANNEL_WHATSAPP,
            'template_name' => 'seasonal_offer_v2',
            'segment_id' => $this->makeSegment()->id,
        ]);

        app(CampaignSender::class)->prepare($campaign);

        $this->assertSame('+92 301 2222222', CampaignSend::sole()->to);
    }

    /** Somebody with no address on the channel is not a consent failure and is not reported as one. */
    public function test_a_recipient_with_no_address_is_not_counted_as_a_consent_refusal(): void
    {
        Lead::create(['company_name' => 'No Email Ltd']);

        $result = app(CampaignSender::class)->prepare($this->makeCampaign());

        $this->assertSame(0, $result['prepared']);
        $this->assertSame(0, $result['skipped'], 'No address is not a refusal.');
    }

    /** Preparing twice does not double the sends — on WhatsApp that would risk the number. */
    public function test_preparing_twice_does_not_duplicate_sends(): void
    {
        $lead = Lead::create(['company_name' => 'Once Ltd', 'email' => 'once@example.test']);
        Consent::grant($lead, Consent::CHANNEL_EMAIL);

        $campaign = $this->makeCampaign();
        app(CampaignSender::class)->prepare($campaign);
        app(CampaignSender::class)->prepare($campaign->fresh());

        $this->assertSame(1, CampaignSend::count());
    }

    /** A segment is resolved at send time, so somebody added later is included. */
    public function test_the_audience_is_resolved_when_prepared_not_when_saved(): void
    {
        $segment = $this->makeSegment();
        $campaign = $this->makeCampaign(['segment_id' => $segment->id]);

        // Added after the campaign was created.
        $lead = Lead::create(['company_name' => 'Latecomer Ltd', 'email' => 'late@example.test']);
        Consent::grant($lead, Consent::CHANNEL_EMAIL);

        $this->assertSame(1, app(CampaignSender::class)->prepare($campaign)['permitted']);
    }

    /** A campaign already sent cannot be sent again. */
    public function test_a_sent_campaign_cannot_be_sent_again(): void
    {
        $campaign = $this->makeCampaign();
        app(CampaignSender::class)->prepare($campaign);
        app(CampaignSender::class)->send($campaign->fresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be sent');

        app(CampaignSender::class)->send($campaign->fresh());
    }

    /** Contacts are only included when Invoicing is licensed to have any. */
    public function test_a_segment_including_contacts_is_empty_without_invoicing(): void
    {
        Contact::create(['name' => 'A customer', 'kind' => Contact::KIND_CUSTOMER, 'email' => 'c@example.test']);

        $segment = Segment::create([
            'name' => 'Customers',
            'definition' => ['include_leads' => false, 'include_contacts' => true],
        ]);

        $this->assertCount(1, app(CampaignSender::class)->audienceFor($segment));

        $this->setModule('invoicing', false);

        $this->assertCount(0, app(CampaignSender::class)->audienceFor($segment));
    }
}
