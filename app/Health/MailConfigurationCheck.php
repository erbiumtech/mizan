<?php

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Can this installation actually deliver an email?
 *
 * Every failure this watches for is silent until the first send, and the first send is usually
 * something that matters — a payslip, a password reset, the notification saying a backup failed.
 * All three were found on one production server in one day:
 *
 *  - **A mailer named in the chain that does not exist.** `MAIL_MAILER=failover` was set before the
 *    release defining the `sendgrid` and `mailgun` mailers was deployed, so every send threw
 *    `Mailer [mailgun] is not defined` — including the health notification that was trying to
 *    report a different failure, which took `health:check` down with it.
 *  - **A provider in the chain with no credentials.** An empty `SENDGRID_API_KEY` is not a
 *    configuration error until a message is handed to it, and then it is a 401 per message.
 *  - **The shipped placeholder as the from-address.** `hello@example.com` is a valid address and
 *    passes every format check; SendGrid and Mailgun both refuse to send from it, and
 *    `HEALTH_TO_ADDRESS` falls back to it, so the warning about the refusal is addressed to the
 *    address that caused it.
 *
 * **Production only**, following `BackupConfigurationCheck` beside it: development mails to the log
 * with placeholder credentials on purpose, and two permanent ambers are how a dashboard stops being
 * read.
 */
class MailConfigurationCheck extends Check
{
    /** What ships in config/mail.php, and what no real provider will send from. */
    public const PLACEHOLDER_FROM = 'hello@example.com';

    /**
     * What each API transport cannot send without, as config key => the variable to set.
     *
     * Keyed by **transport**, not by mailer name: `mailgun` is the name of a mailer that may be the
     * provider's HTTP API or plain SMTP to `smtp.mailgun.org`, and those need entirely different
     * credentials. Reading the name would have this check demand an API key from a working SMTP relay.
     */
    private const API_CREDENTIALS = [
        'sendgrid' => ['services.sendgrid.key' => 'SENDGRID_API_KEY'],
        'mailgun' => ['services.mailgun.domain' => 'MAILGUN_DOMAIN', 'services.mailgun.secret' => 'MAILGUN_SECRET'],
        'postmark' => ['services.postmark.token' => 'POSTMARK_TOKEN'],
        'resend' => ['services.resend.key' => 'RESEND_KEY'],
    ];

    /** Transports that also accept their credential inline on the mailer, as transport => key. */
    private const INLINE_CREDENTIAL = [
        'sendgrid' => 'key',
        'resend' => 'key',
        'postmark' => 'token',
    ];

    public function run(): Result
    {
        $chain = $this->chain();

        $result = Result::make()->meta([
            'mailer' => (string) config('mail.default'),
            'chain' => $chain,
            'from' => (string) config('mail.from.address'),
        ]);

        $problems = [];
        $warnings = [];

        foreach ($chain as $mailer) {
            // `is_null`, because that is the test `MailManager::resolve()` applies before throwing the
            // very message quoted below — a key present but empty is undefined as far as a send is
            // concerned, and the check has to agree with the code that does the sending.
            if (config("mail.mailers.{$mailer}") === null) {
                $problems[] = sprintf(
                    'the mailer [%s] is named in mail.default but not defined in config/mail.php, so every '
                    .'send throws — deploy the release that defines it, then `php artisan config:cache`',
                    $mailer,
                );

                continue;
            }

            foreach ($this->missingCredentials((array) config("mail.mailers.{$mailer}")) as $missing) {
                $problems[] = "the [{$mailer}] mailer is in the chain with no {$missing}";
            }
        }

        $from = (string) config('mail.from.address');

        if ($from === self::PLACEHOLDER_FROM) {
            $problems[] = 'MAIL_FROM_ADDRESS is still '.self::PLACEHOLDER_FROM
                .', which SendGrid and Mailgun both refuse to send from';
        }

        // Nothing is being delivered. Not a failure — somebody may have chosen it — but it must not
        // read as green, because every send is reported as successful and none of them went.
        if ($undelivered = array_intersect($chain, ['log', 'array'])) {
            $warnings[] = 'mail is written to the '.implode(' and ', $undelivered)
                .' driver rather than sent, so nothing reaches anybody and every send still reports success';
        }

        $warnings = array_merge($warnings, $this->senderDomainWarnings($chain, $from));

        if ($problems !== []) {
            return $result
                ->shortSummary('misconfigured')
                ->failed(ucfirst(implode('; ', array_merge($problems, $warnings))).'.');
        }

        return $warnings === []
            ? $result->shortSummary(implode(' → ', $chain))->ok('Mail is configured and every mailer in the chain can send.')
            : $result->shortSummary('check the sender')->warning(ucfirst(implode('; ', $warnings)).'.');
    }

    /**
     * Whether the address being sent *from* is one the provider will actually send *for*.
     *
     * Mailgun authenticates a domain and refuses a From outside it; SendGrid checks the address
     * against a verified Sender Identity. Both rejections arrive per message, at the provider, long
     * after the configuration looked complete — and `mg.erbium.ch` against a From of
     * `info@erbium.tech` is two different registrable domains, which is easy to write and hard to
     * spot. A subdomain of the sending domain is the normal, recommended arrangement and passes.
     *
     * A warning rather than a failure: a paid plan or a verified single sender can legitimately
     * differ, and this check cannot ask the provider which.
     *
     * @param  array<int, string>  $chain
     * @return array<int, string>
     */
    private function senderDomainWarnings(array $chain, string $from): array
    {
        $fromDomain = strtolower((string) (explode('@', $from)[1] ?? ''));
        $mailgunDomain = strtolower((string) config('services.mailgun.domain'));

        if (! in_array('mailgun', $chain, true) || blank($fromDomain) || blank($mailgunDomain)) {
            return [];
        }

        $related = $fromDomain === $mailgunDomain
            || str_ends_with($fromDomain, '.'.$mailgunDomain)
            || str_ends_with($mailgunDomain, '.'.$fromDomain);

        return $related ? [] : [sprintf(
            'MAIL_FROM_ADDRESS is at %s while Mailgun authenticates %s, which are unrelated domains — '
            .'Mailgun will refuse the From unless that address is verified for it',
            $fromDomain,
            $mailgunDomain,
        )];
    }

    /**
     * What the mailer is missing before it can send, in the words of whoever has to set it.
     *
     * SMTP needs somewhere to connect, and a username with no password is a relay that will be
     * refused rather than one that is open. An API transport needs its credentials, from
     * config/services.php or — where the transport allows it — inline on the mailer itself.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function missingCredentials(array $config): array
    {
        $transport = (string) ($config['transport'] ?? '');

        if ($transport === 'smtp') {
            return array_values(array_filter([
                blank($config['host'] ?? null) ? 'host set' : null,
                filled($config['username'] ?? null) && blank($config['password'] ?? null)
                    ? 'password for its username' : null,
            ]));
        }

        $missing = [];
        $inline = self::INLINE_CREDENTIAL[$transport] ?? null;

        foreach (self::API_CREDENTIALS[$transport] ?? [] as $key => $label) {
            if (filled(config($key)) || ($inline !== null && filled($config[$inline] ?? null))) {
                continue;
            }

            $missing[] = "{$label} set";
        }

        return $missing;
    }

    /**
     * The mailers a send actually reaches: a failover or round-robin chain's members, else the one
     * named. Not recursed — a chain of chains is not a thing anybody configures, and pretending to
     * support it would hide the simple case this check exists for.
     *
     * @return array<int, string>
     */
    private function chain(): array
    {
        $default = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$default}.transport", $default);

        return in_array($transport, ['failover', 'roundrobin'], true)
            ? array_values((array) config("mail.mailers.{$default}.mailers", []))
            : [$default];
    }
}
