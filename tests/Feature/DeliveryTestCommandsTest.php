<?php

namespace Tests\Feature;

use App\Support\DeliveryTestLink;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The two commands that answer "will this actually send" before something that matters asks.
 *
 * Both are about the delivery path rather than the message, so what is asserted is that the path was
 * walked: a message in the transport, a document at the sender, a non-zero exit when a provider
 * refuses. The wording of the test message is not worth a test.
 */
class DeliveryTestCommandsTest extends TestCase
{
    private function arrayTransport(): object
    {
        return Mail::mailer('array')->getSymfonyTransport();
    }

    public function test_mail_test_sends_one_message_to_the_address_given(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'payroll@example.test']);

        $before = $this->arrayTransport()->messages()->count();

        $this->artisan('mail:test', ['to' => 'somebody@example.test'])
            ->expectsOutputToContain('accepted')
            ->assertSuccessful();

        $messages = $this->arrayTransport()->messages();

        $this->assertCount($before + 1, $messages);
        $this->assertSame('somebody@example.test', $messages->last()->getEnvelope()->getRecipients()[0]->getAddress());
    }

    /** With no argument it goes where the health notifications go, which is the address to prove. */
    public function test_mail_test_falls_back_to_the_configured_notification_address(): void
    {
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'payroll@example.test',
            'health.notifications.mail.to' => 'ops@example.test',
        ]);

        $this->artisan('mail:test')->assertSuccessful();

        $recipients = $this->arrayTransport()->messages()->last()->getEnvelope()->getRecipients();

        $this->assertSame('ops@example.test', $recipients[0]->getAddress());
    }

    /**
     * The reason this command exists on a failover chain: the chain hides its own fallback.
     *
     * One message through the chain proves only the first provider. `--each` sends through every
     * member so the standby is known to work rather than assumed to.
     */
    public function test_each_sends_separately_through_every_mailer_in_the_chain(): void
    {
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['array', 'second']],
            'mail.mailers.second' => ['transport' => 'array'],
            'mail.from.address' => 'payroll@example.test',
        ]);

        $before = $this->arrayTransport()->messages()->count();

        $this->artisan('mail:test', ['to' => 'somebody@example.test', '--each' => true])
            ->expectsOutputToContain('array')
            ->expectsOutputToContain('second')
            ->assertSuccessful();

        $this->assertCount($before + 1, $this->arrayTransport()->messages(), 'one of the two went through this transport');
    }

    /** The production failure: a chain naming a mailer nothing defines. It must exit non-zero. */
    public function test_mail_test_fails_when_a_mailer_in_the_chain_is_not_defined(): void
    {
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['array', 'mailgun']],
            'mail.mailers.mailgun' => null,
            'mail.from.address' => 'payroll@example.test',
        ]);

        $this->artisan('mail:test', ['to' => 'somebody@example.test', '--each' => true])
            ->expectsOutputToContain('not defined')
            ->assertFailed();
    }

    // ───────────────────────────────── WhatsApp ─────────────────────────────────

    public function test_whatsapp_test_sends_a_document_to_the_normalised_number(): void
    {
        config(['pdf.driver' => 'dompdf', 'whatsapp.driver' => 'twilio', 'whatsapp.default_country_code' => '92']);

        $sender = new RecordingWhatsAppSender;
        $this->app->instance(WhatsAppSender::class, $sender);

        // A local number, as somebody would type it off an employee record.
        $this->artisan('whatsapp:test', ['to' => '03001234567'])->assertSuccessful();

        $this->assertSame('923001234567', $sender->to);
        $this->assertStringStartsWith('%PDF', $sender->bytes, 'the document is a real PDF, as a payslip is');

        // Twilio fetches media rather than receiving it, so a document with no URL cannot be sent
        // at all — which is what made this command useless for the driver that is in production.
        $this->assertStringContainsString('/delivery-test/', $sender->url);
        $this->assertStringNotContainsString('?', $sender->url, 'a query string does not survive a Twilio template');
    }

    public function test_the_media_url_can_be_overridden_for_a_document_served_elsewhere(): void
    {
        config(['pdf.driver' => 'dompdf', 'whatsapp.driver' => 'twilio']);

        $sender = new RecordingWhatsAppSender;
        $this->app->instance(WhatsAppSender::class, $sender);

        $this->artisan('whatsapp:test', ['to' => '923001234567', '--url' => 'https://example.test/x.pdf'])
            ->assertSuccessful();

        $this->assertSame('https://example.test/x.pdf', $sender->url);
    }

    // ─────────────────────── the link the provider fetches ───────────────────────

    public function test_the_signed_link_serves_a_pdf_without_a_session(): void
    {
        config(['pdf.driver' => 'dompdf']);

        $response = $this->get(DeliveryTestLink::for());

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_tampered_or_expired_link_is_refused(): void
    {
        $valid = DeliveryTestLink::for();

        // One character of the signature changed: the whole point of signing it.
        $this->get(str_replace('/connection-test.pdf', 'x/connection-test.pdf', $valid))->assertNotFound();

        $this->travel(20)->minutes();
        $this->get($valid)->assertForbidden();
    }

    /** The log driver reports success and sends nothing, which must never read as a pass. */
    public function test_whatsapp_test_says_so_when_the_driver_only_writes_to_the_log(): void
    {
        config(['pdf.driver' => 'dompdf', 'whatsapp.driver' => 'log']);

        $this->artisan('whatsapp:test', ['to' => '923001234567'])
            ->expectsOutputToContain('Nothing was sent')
            ->assertSuccessful();
    }

    public function test_whatsapp_test_fails_when_the_provider_refuses(): void
    {
        config(['pdf.driver' => 'dompdf', 'whatsapp.driver' => 'twilio']);

        $this->app->instance(WhatsAppSender::class, new RefusingWhatsAppSender);

        $this->artisan('whatsapp:test', ['to' => '923001234567'])
            ->expectsOutputToContain('refused it')
            ->assertFailed();
    }

    public function test_whatsapp_test_refuses_a_number_it_cannot_reach(): void
    {
        $this->artisan('whatsapp:test', ['to' => 'not-a-number'])->assertFailed();
    }
}

class RecordingWhatsAppSender implements WhatsAppSender
{
    public ?string $to = null;

    public ?string $bytes = null;

    public ?string $url = null;

    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
    {
        $this->to = $to;
        $this->bytes = $document->bytes();
        $this->url = $document->hasUrl() ? $document->url() : null;

        return 'recorded-1';
    }
}

class RefusingWhatsAppSender implements WhatsAppSender
{
    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
    {
        throw new WhatsAppException('The From number is not a WhatsApp sender on this account.');
    }
}
