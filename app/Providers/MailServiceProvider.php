<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The mail transports Laravel does not ship with.
 *
 * SendGrid is the first provider in config/mail.php's failover chain, and the framework knows
 * Mailgun, Postmark, SES and Resend but not it. Symfony's bridge does; this hands the bridge's
 * factory a DSN built from the mailer's config, which is all `Mail::extend()` asks for. The
 * `+api` scheme is the HTTP API rather than SMTP relay — see the note in config/mail.php.
 */
class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('sendgrid', function (array $config): TransportInterface {
            $key = $config['key'] ?? config('services.sendgrid.key');

            return (new SendgridTransportFactory)->create(
                new Dsn('sendgrid+api', 'default', (string) $key),
            );
        });
    }
}
