<?php

namespace App\Console\Commands;

use App\Support\DeliveryTestLink;
use App\Support\Pdf\Pdf;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prove that this installation can send a payslip on WhatsApp, before payroll tries to.
 *
 * It sends a small PDF rather than a text message, because that is what the application actually
 * sends and because the document is where the two providers differ: Meta uploads the bytes, Twilio
 * fetches a link. A test that sent text would pass on a server where payslips cannot leave.
 *
 * It therefore also renders a PDF, which makes this a pre-flight for the payslip pipeline as a whole
 * — a host with no Chrome and no working Dompdf fails here rather than on payroll night.
 *
 * **The `log` driver reports success and sends nothing**, which is correct for development and a trap
 * in production, so this says so in as many words rather than printing a green tick.
 *
 * Twilio fetches media by link, so the document is served from a short-lived signed URL
 * ({@see DeliveryTestLink}) rather than uploaded. That URL is printed, because when Twilio reports
 * error 11200 the question is always whether it could reach the host, and `curl` answers it.
 */
class TestWhatsApp extends Command
{
    protected $signature = 'whatsapp:test
                            {to : The number to message, with or without the country code}
                            {--url= : Serve the document from here instead of this application}';

    protected $description = 'Send a test document on WhatsApp and report what the provider said';

    public function handle(WhatsAppSender $sender): int
    {
        $driver = (string) config('whatsapp.driver');
        $number = PhoneNumber::e164($this->argument('to'), (string) config('whatsapp.default_country_code'));

        if ($number === null) {
            $this->components->error(
                'That is not a number WhatsApp can reach. Give it in full, or set WHATSAPP_COUNTRY_CODE.'
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Driver</>', $driver);
        $this->components->twoColumnDetail('<fg=gray>Sender</>', $sender::class);
        $this->components->twoColumnDetail('<fg=gray>To</>', '+'.$number);
        $this->components->twoColumnDetail('<fg=gray>Media</>', $this->mediaUrl());
        $this->newLine();

        try {
            $document = $this->document();
        } catch (Throwable $exception) {
            $this->components->error('The PDF could not be rendered, so a payslip could not be either.');
            $this->line('  <fg=red>'.$exception->getMessage().'</>');

            return self::FAILURE;
        }

        try {
            $id = $sender->sendDocument($number, $document, 'Test message from '.config('app.name'));
        } catch (WhatsAppException $exception) {
            $this->components->error('The provider refused it.');
            $this->line('  <fg=red>'.$exception->getMessage().'</>');
            $this->line('  <fg=yellow>If it could not fetch the media, check that link from outside the '
                .'server: `curl -I "'.$this->mediaUrl().'"`. It must answer 200 with application/pdf, '
                .'which means APP_URL has to be the public address and the site must not sit behind '
                .'basic auth or an IP allow-list.</>');

            return self::FAILURE;
        }

        if ($sender::class === \App\Support\WhatsApp\LogWhatsAppSender::class) {
            $this->components->warn('Nothing was sent. WHATSAPP_DRIVER is "'.$driver.'", which writes to the '
                .'log and reports success — including when payroll sends a real payslip.');
            $this->line('  <fg=gray>Set WHATSAPP_DRIVER=twilio with TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and '
                .'TWILIO_WHATSAPP_FROM, or WHATSAPP_DRIVER=cloud for the Meta Cloud API.</>');

            return self::SUCCESS;
        }

        $this->components->info("Sent. The provider's message id is {$id}.");
        $this->line('  <fg=gray>Sent is not delivered: the number must have accepted a message from this '
            .'sender within 24 hours, or the template must be approved.</>');

        return self::SUCCESS;
    }

    /** A small real PDF, rendered the way a payslip is, and reachable the way a payslip is. */
    private function document(): WhatsAppDocument
    {
        return new WhatsAppDocument(
            filename: DeliveryTestLink::FILENAME,
            bytes: fn (): string => Pdf::view('pdfs.delivery-test', [
                'application' => config('app.name'),
                'sentAt' => now()->toDayDateTimeString(),
            ])->raw(),
            url: fn (): string => $this->mediaUrl(),
        );
    }

    /** Where the provider is told to collect the document. Signed, and good for fifteen minutes. */
    private function mediaUrl(): string
    {
        return $this->mediaUrl ??= ($this->option('url') ?: DeliveryTestLink::for(15));
    }

    private ?string $mediaUrl = null;
}
