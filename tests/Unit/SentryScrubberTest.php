<?php

namespace Tests\Unit;

use App\Support\SentryScrubber;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

/**
 * What may leave for Sentry — see App\Support\SentryScrubber.
 *
 * The cases are the leaks that actually happen: a unique-constraint violation
 * quoting the duplicate email, a failed notification naming its recipient's
 * phone, a log line holding a CNIC. Plain PHPUnit, no app boot: the scrubber is
 * two pure callables and must stay that way, because config:cache serializes the
 * reference to them.
 */
class SentryScrubberTest extends TestCase
{
    public function test_exception_values_lose_emails_cnics_and_digit_runs(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new RuntimeException(
            "Duplicate entry 'ayesha.khan@example.com' for key 'leads_email_unique', "
            .'CNIC 42101-1234567-1, phone +92 300 1234567'
        ))]);

        $value = SentryScrubber::scrub($event)->getExceptions()[0]->getValue();

        $this->assertSame(
            "Duplicate entry '[email]' for key 'leads_email_unique', CNIC [cnic], phone [number]",
            $value,
        );
    }

    public function test_request_and_extra_context_are_scrubbed_recursively(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://x.test/leads?email=a@b.ch', 'method' => 'POST']);
        $event->setExtra(['recipient' => ['email' => 'hr@x.pk', 'iban_digits' => '00762 011 6238 5295 7']]);

        $event = SentryScrubber::scrub($event);

        $this->assertSame('https://x.test/leads?email=[email]', $event->getRequest()['url']);
        $this->assertSame(['email' => '[email]', 'iban_digits' => '[number]'], $event->getExtra()['recipient']);
    }

    public function test_breadcrumbs_are_scrubbed_message_and_metadata(): void
    {
        $breadcrumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'notification',
            'Payslip mailed to omar@erbium.ch',
            ['to' => 'omar@erbium.ch', 'attempt' => 2],
        );

        $scrubbed = SentryScrubber::scrubBreadcrumb($breadcrumb);

        $this->assertSame('Payslip mailed to [email]', $scrubbed->getMessage());
        $this->assertSame(['to' => '[email]', 'attempt' => 2], $scrubbed->getMetadata());
    }

    public function test_ordinary_error_text_survives_untouched(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new RuntimeException(
            'Undefined array key "sort" at line 179, company 1, batch #42',
        ))]);

        $this->assertSame(
            'Undefined array key "sort" at line 179, company 1, batch #42',
            SentryScrubber::scrub($event)->getExceptions()[0]->getValue(),
        );
    }
}
