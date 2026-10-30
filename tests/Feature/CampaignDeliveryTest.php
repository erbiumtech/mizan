<?php

namespace Tests\Feature;

use App\Modules\Campaigns\Filament\Settings\CampaignSendingSettingsSection;
use App\Modules\Campaigns\Jobs\DeliverCampaign;
use App\Modules\Campaigns\Mail\CampaignMessage;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Services\CampaignSender;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Support\TenantSettings;
use App\Support\WhatsApp\SendsTemplates;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A campaign's Send delivers — docs/crms-plan.md §6 ("bulk sending goes through the existing queue").
 *
 * It used to mark every permitted row `sent` and hand nothing to anybody. These pin that each recipient's
 * message now goes through the mail or WhatsApp sender, from the queue, a batch at a time at the company's
 * pace; that consent is checked per message; that a refusal fails one recipient with the provider's reason;
 * and that a send which stops part-way is paused, resumable, and never sends anybody the same thing twice.
 */
class CampaignDeliveryTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['crm', 'campaigns', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        Mail::fake();
    }

    private function consentingLead(string $name, string $email, ?string $whatsapp = null, string $channel = Consent::CHANNEL_EMAIL): Lead
    {
        $lead = Lead::create(['company_name' => $name.' Ltd', 'person_name' => $name, 'email' => $email, 'whatsapp' => $whatsapp]);
        Consent::grant($lead, $channel, 'test');

        return $lead;
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'name' => 'Winter range',
            'channel' => Campaign::CHANNEL_EMAIL,
            'subject' => 'Our winter range, {name}',
            'body' => "Dear {name},\n\nThe winter range is in.",
            'segment_id' => Segment::create(['name' => 'Open leads', 'definition' => ['include_leads' => true]])->id,
        ], $attributes));
    }

    /** A WhatsApp provider that can send templates, recording what it was asked to send. */
    private function templateSender(?string $refuse = null): WhatsAppSender
    {
        return new class($refuse) implements SendsTemplates, WhatsAppSender
        {
            public array $templates = [];

            public array $texts = [];

            public function __construct(private ?string $refuse) {}

            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                return 'doc';
            }

            public function sendText(string $to, string $text): string
            {
                $this->texts[] = $to;

                return 'text';
            }

            public function sendTemplate(string $to, string $template, array $parameters = []): string
            {
                if ($this->refuse !== null && str_ends_with($to, $this->refuse)) {
                    throw new WhatsAppException('(#131026) Message undeliverable');
                }

                $this->templates[] = compact('to', 'template', 'parameters');

                return 'wamid.'.count($this->templates);
            }
        };
    }

    public function test_send_emails_each_consenting_recipient_through_the_mailer(): void
    {
        $this->consentingLead('Imran', 'imran@example.test');
        $this->consentingLead('Nadia', 'nadia@example.test');
        Lead::create(['company_name' => 'Silent Ltd', 'email' => 'silent@example.test']);

        $campaign = $this->campaign();
        app(CampaignSender::class)->prepare($campaign);
        $result = app(CampaignSender::class)->send($campaign->fresh());

        $this->assertSame(['sent' => 2, 'failed' => 0, 'skipped' => 1, 'queued' => 0], $result);
        Mail::assertSent(CampaignMessage::class, 2);
        Mail::assertSent(CampaignMessage::class, fn (CampaignMessage $mail): bool => $mail->hasTo('imran@example.test')
            && $mail->subjectLine === 'Our winter range, Imran'
            && str_contains($mail->body, 'Dear Imran'));
        Mail::assertNotSent(CampaignMessage::class, fn (CampaignMessage $mail): bool => $mail->hasTo('silent@example.test'));
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
        $this->assertNotNull(CampaignSend::where('status', CampaignSend::STATUS_SENT)->first()->sent_at);
    }

    /** Pressing Send on a campaign nobody checked the audience of is never a send to nobody. */
    public function test_send_prepares_the_audience_when_nothing_was_prepared(): void
    {
        $this->consentingLead('Imran', 'imran@example.test');
        $campaign = $this->campaign();

        $this->assertSame(0, $campaign->sends()->count());

        $result = app(CampaignSender::class)->send($campaign);

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(CampaignMessage::class, 1);
    }

    public function test_consent_withdrawn_after_preparing_is_skipped_with_the_reason(): void
    {
        $lead = $this->consentingLead('Asad', 'asad@example.test');
        $campaign = $this->campaign();
        app(CampaignSender::class)->prepare($campaign);

        Consent::revoke($lead, Consent::CHANNEL_EMAIL, 'replied STOP');
        app(CampaignSender::class)->send($campaign->fresh());

        Mail::assertNothingSent();
        $this->assertSame(CampaignSend::STATUS_SKIPPED_NO_CONSENT, CampaignSend::sole()->status);
        $this->assertSame(CampaignSend::REASON_CONSENT_WITHDRAWN, CampaignSend::sole()->failed_reason);
    }

    public function test_a_whatsapp_campaign_sends_the_approved_template_with_the_body_lines_as_variables(): void
    {
        $this->app->instance(WhatsAppSender::class, $sender = $this->templateSender());
        $this->consentingLead('Imran', 'imran@example.test', '+92 301 2222222', Consent::CHANNEL_WHATSAPP);

        $campaign = $this->campaign(['channel' => Campaign::CHANNEL_WHATSAPP, 'template_name' => 'winter_offer_v1', 'body' => "{name}\n20% off until 30 November"]);
        app(CampaignSender::class)->send($campaign);

        $this->assertSame([['to' => '923012222222', 'template' => 'winter_offer_v1', 'parameters' => ['Imran', '20% off until 30 November']]], $sender->templates);
        $this->assertSame([], $sender->texts, 'a campaign must never fall back to free text');
        $this->assertSame(CampaignSend::STATUS_SENT, CampaignSend::sole()->status);
    }

    public function test_a_provider_that_cannot_send_templates_fails_the_recipient_and_sends_no_text(): void
    {
        $plain = new class implements WhatsAppSender
        {
            public int $texts = 0;

            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                return 'doc';
            }

            public function sendText(string $to, string $text): string
            {
                $this->texts++;

                return 'text';
            }
        };
        $this->app->instance(WhatsAppSender::class, $plain);
        $this->consentingLead('Imran', 'imran@example.test', '+92 301 2222222', Consent::CHANNEL_WHATSAPP);

        app(CampaignSender::class)->send($this->campaign(['channel' => Campaign::CHANNEL_WHATSAPP, 'template_name' => 'winter_offer_v1']));

        $this->assertSame(0, $plain->texts);
        $this->assertSame(CampaignSend::STATUS_FAILED, CampaignSend::sole()->status);
        $this->assertSame(CampaignSend::REASON_NO_TEMPLATES, CampaignSend::sole()->failed_reason);
    }

    public function test_one_refused_recipient_fails_with_the_providers_reason_and_the_rest_go(): void
    {
        $this->app->instance(WhatsAppSender::class, $this->templateSender(refuse: '1112202'));
        $this->consentingLead('Imran', 'a@example.test', '+92 300 1112201', Consent::CHANNEL_WHATSAPP);
        $this->consentingLead('Nadia', 'b@example.test', '+92 300 1112202', Consent::CHANNEL_WHATSAPP);

        $result = app(CampaignSender::class)->send($this->campaign(['channel' => Campaign::CHANNEL_WHATSAPP, 'template_name' => 'winter_offer_v1']));

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('undeliverable', CampaignSend::where('status', CampaignSend::STATUS_FAILED)->sole()->failed_reason);
    }

    /**
     * A batch that breaks is paused, not left "sending"; resuming sends to whoever is still waiting; and
     * the one being handed over when it broke is failed as interrupted rather than sent again.
     */
    public function test_a_send_that_stops_part_way_is_paused_and_resumes_without_sending_anybody_twice(): void
    {
        foreach (['one', 'two', 'three'] as $who) {
            $this->consentingLead(ucfirst($who), "{$who}@example.test");
        }

        $campaign = $this->campaign();
        app(CampaignSender::class)->prepare($campaign);

        $broken = false;
        CampaignSend::updating(function (CampaignSend $send) use (&$broken): void {
            if (! $broken && $send->status === CampaignSend::STATUS_SENT && $send->getOriginal('status') === CampaignSend::STATUS_SENDING
                && CampaignSend::where('status', CampaignSend::STATUS_SENT)->exists()) {
                $broken = true;

                throw new RuntimeException('Lost the database connection');
            }
        });

        try {
            app(CampaignSender::class)->send($campaign->fresh());
            $this->fail('the batch should have stopped');
        } catch (RuntimeException $e) {
            $this->assertSame('Lost the database connection', $e->getMessage());
        }

        $campaign->refresh();
        $this->assertSame(Campaign::STATUS_PAUSED, $campaign->status);
        $this->assertTrue($campaign->isResumable());
        $this->assertSame(1, CampaignSend::where('status', CampaignSend::STATUS_SENT)->count());
        $this->assertSame(1, CampaignSend::where('failed_reason', CampaignSend::REASON_INTERRUPTED)->count());
        $this->assertSame(1, CampaignSend::where('status', CampaignSend::STATUS_PENDING)->count());

        app(CampaignSender::class)->send($campaign);

        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
        Mail::assertSent(CampaignMessage::class, 3);
        foreach (['one', 'two', 'three'] as $who) {
            $this->assertCount(1, Mail::sent(CampaignMessage::class, fn (CampaignMessage $mail): bool => $mail->hasTo("{$who}@example.test")), "{$who} was mailed twice or not at all");
        }
    }

    public function test_retry_failed_sends_to_the_failures_and_nobody_else(): void
    {
        $this->app->instance(WhatsAppSender::class, $this->templateSender(refuse: '1112202'));
        $this->consentingLead('Imran', 'a@example.test', '+92 300 1112201', Consent::CHANNEL_WHATSAPP);
        $this->consentingLead('Nadia', 'b@example.test', '+92 300 1112202', Consent::CHANNEL_WHATSAPP);
        $campaign = $this->campaign(['channel' => Campaign::CHANNEL_WHATSAPP, 'template_name' => 'winter_offer_v1']);
        app(CampaignSender::class)->send($campaign);

        $this->app->instance(WhatsAppSender::class, $sender = $this->templateSender());
        $this->assertSame(1, app(CampaignSender::class)->retryFailed($campaign->fresh()));

        $this->assertCount(1, $sender->templates);
        $this->assertSame('923001112202', $sender->templates[0]['to']);
        $this->assertSame(2, CampaignSend::where('status', CampaignSend::STATUS_SENT)->count());
        $this->assertSame(Campaign::STATUS_SENT, $campaign->fresh()->status);
    }

    /** The default pace: one job per batch of fifty, the next a minute later. */
    public function test_the_default_batch_is_fifty_a_minute(): void
    {
        $this->assertSame(50, CampaignSender::batchSize());
        $this->assertSame(60, CampaignSender::batchInterval());
    }

    /** The company's pace: two per batch with a five-second pause sends two, then queues the next. */
    public function test_the_batch_size_and_pause_are_the_companys(): void
    {
        app(TenantSettings::class)->set('campaigns.send.batch_size', 2);
        app(TenantSettings::class)->set('campaigns.send.batch_interval_seconds', 5);

        foreach (['one', 'two', 'three'] as $who) {
            $this->consentingLead(ucfirst($who), "{$who}@example.test");
        }

        Queue::fake();
        $campaign = $this->campaign();
        app(CampaignSender::class)->send($campaign);
        Queue::assertPushed(DeliverCampaign::class, 1);

        (new DeliverCampaign($campaign->id))->handle(app(CampaignSender::class));

        $this->assertSame(2, CampaignSend::where('status', CampaignSend::STATUS_SENT)->count());
        $this->assertSame(1, CampaignSend::where('status', CampaignSend::STATUS_PENDING)->count());
        Queue::assertPushed(DeliverCampaign::class, fn (DeliverCampaign $job): bool => $job->delay !== null
            && abs(now()->diffInSeconds($job->delay, false) - 5) <= 1);
        $this->assertSame(Campaign::STATUS_SENDING, $campaign->fresh()->status);
    }

    public function test_the_settings_section_saves_the_pace_and_keeps_it_in_bounds(): void
    {
        $section = app(CampaignSendingSettingsSection::class);

        $this->assertSame(['campaigns_batch_size' => 50, 'campaigns_batch_interval_seconds' => 60], $section->fill());

        $section->save(['campaigns_batch_size' => 5000, 'campaigns_batch_interval_seconds' => 0]);

        $this->assertSame(1000, CampaignSender::batchSize());
        $this->assertSame(0, CampaignSender::batchInterval());
    }
}
