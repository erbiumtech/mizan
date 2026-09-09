<?php

namespace App\Console\Commands;

use App\Health\MailConfigurationCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prove that this installation can send an email, before something that matters tries to.
 *
 * Every mail failure this application has had was invisible until the first real send, and the first
 * real send is always a payslip or a password reset. A production server spent a day writing mail to
 * the log and reporting success, then a day throwing `Mailer [mailgun] is not defined` — neither of
 * which any amount of reading the config would have shown.
 *
 * **`--each` is the point of this command on a failover chain.** A chain hides its own failures by
 * design: if the first provider works you never learn whether the second one would, and you find out
 * on the night the first is down. This sends one message through every provider in turn and reports
 * them separately, so "the fallback works" is something you know rather than assume.
 */
class TestMail extends Command
{
    protected $signature = 'mail:test
                            {to? : Where to send it. Defaults to HEALTH_TO_ADDRESS, then MAIL_FROM_ADDRESS}
                            {--mailer= : Send through one named mailer instead of the configured default}
                            {--each : Send separately through every mailer in the failover chain}';

    protected $description = 'Send a test email and report which provider accepted it';

    public function handle(): int
    {
        $to = $this->argument('to')
            ?: config('health.notifications.mail.to')
            ?: config('mail.from.address');

        if (blank($to)) {
            $this->components->error('No recipient. Pass one as an argument, or set MAIL_FROM_ADDRESS.');

            return self::FAILURE;
        }

        $this->configuration();

        $failures = 0;

        foreach ($this->targets() as $mailer) {
            $failures += $this->send($mailer, (string) $to) ? 0 : 1;
        }

        $this->newLine();

        if ($failures > 0) {
            $this->components->error("{$failures} of these did not send. Nothing was delivered by them.");

            return self::FAILURE;
        }

        $this->components->info("Accepted for delivery to {$to}. Check the inbox, and the spam folder.");
        $this->line('  <fg=gray>Accepted is not delivered: a provider can still bounce it afterwards. '
            .'The provider\'s own dashboard is where a bounce shows.</>');

        return self::SUCCESS;
    }

    /** What the configuration says before anything is sent, so a failure below has context above it. */
    private function configuration(): void
    {
        $default = (string) config('mail.default');

        $this->components->twoColumnDetail('<fg=gray>Mailer</>', $default);
        $this->components->twoColumnDetail('<fg=gray>From</>', (string) config('mail.from.address'));

        if ($chain = $this->chain()) {
            $this->components->twoColumnDetail('<fg=gray>Chain</>', implode(' → ', $chain));
        }

        $result = (new MailConfigurationCheck)->run();

        $this->components->twoColumnDetail(
            '<fg=gray>Configuration</>',
            match ($result->status->value) {
                'ok' => '<fg=green>ok</>',
                'warning' => '<fg=yellow>'.$result->getNotificationMessage().'</>',
                default => '<fg=red>'.$result->getNotificationMessage().'</>',
            },
        );

        $this->newLine();
    }

    /**
     * The mailers to send through: one named, every member of the chain, or the default alone.
     *
     * @return array<int, string|null>
     */
    private function targets(): array
    {
        if ($named = $this->option('mailer')) {
            return [(string) $named];
        }

        // Null means "whatever mail.default resolves to", which for a chain is the chain itself —
        // one message, delivered by the first provider that accepts it. That is the production path.
        return $this->option('each') ? ($this->chain() ?: [null]) : [null];
    }

    /** The members of a failover or round-robin chain, or an empty array when the default is one mailer. */
    private function chain(): array
    {
        $default = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$default}.transport", $default);

        return in_array($transport, ['failover', 'roundrobin'], true)
            ? array_values((array) config("mail.mailers.{$default}.mailers", []))
            : [];
    }

    private function send(?string $mailer, string $to): bool
    {
        $label = $mailer ?? (string) config('mail.default');
        $subject = config('app.name').' test email'.($mailer ? " via {$mailer}" : '');

        try {
            Mail::mailer($mailer)->raw($this->body($label), function ($message) use ($to, $subject): void {
                $message->to($to)->subject($subject);
            });
        } catch (Throwable $exception) {
            $this->components->twoColumnDetail($label, '<fg=red>failed</>');
            $this->line('  <fg=red>'.$exception->getMessage().'</>');

            return false;
        }

        $this->components->twoColumnDetail($label, '<fg=green>accepted</>');

        return true;
    }

    private function body(string $mailer): string
    {
        return implode(PHP_EOL, [
            'This is a test message from '.config('app.name').'.',
            '',
            'Sent through the ['.$mailer.'] mailer at '.now()->toDateTimeString().'.',
            'If you are reading it, that provider can deliver to this address.',
        ]);
    }
}
