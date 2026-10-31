<?php

namespace App\Support\Contracts;

use App\Modules\Core\Models\DeviceToken;
use App\Support\WebPushOutcome;

/**
 * Delivers one payload to one subscribed browser — docs/desktop-mobile-plan.md A3.
 *
 * An interface so the channel can be tested without a push service and so a second transport (FCM, if a
 * native shell ever arrives) is a second class behind the same seam, not a second channel. The outcome
 * is three-valued on purpose: a subscription the push service says has expired is deleted by the
 * channel, and that is the one piece of feedback a transport must not swallow.
 */
interface WebPushTransport
{
    public function send(DeviceToken $token, string $payload): WebPushOutcome;
}
