<?php

namespace App\Support\WhatsApp;

/**
 * Whether a message sent now would actually reach anybody.
 *
 * The container always resolves a sender — an install with no provider gets
 * {@see LogWhatsAppSender}, which writes to the log and reports success, and that is
 * the right default for a payroll run that must not stop. It is the wrong answer for
 * a feature that is *about* delivery: a phone check nobody can complete is a locked
 * door, and docs/saas-launch-plan.md §14.8 is explicit that the gate never blocks for
 * a check the company cannot perform.
 *
 * Asked of the resolved sender rather than of the configuration, so there is one
 * decision about what counts as configured and it is the binding's.
 */
final class WhatsAppDelivery
{
    public static function configured(): bool
    {
        $sender = app(WhatsAppSender::class);

        if ($sender instanceof LoggingWhatsAppSender) {
            $sender = $sender->inner();
        }

        return ! $sender instanceof LogWhatsAppSender;
    }
}
