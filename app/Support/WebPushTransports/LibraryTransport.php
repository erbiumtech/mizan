<?php

namespace App\Support\WebPushTransports;

use App\Modules\Core\Models\DeviceToken;
use App\Support\Contracts\WebPushTransport;
use App\Support\PushEndpoints;
use App\Support\WebPush;
use App\Support\WebPushOutcome;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as Library;
use Throwable;

/**
 * The real thing: minishlink/web-push signing each message with this installation's VAPID key.
 *
 * One message per call rather than the library's queue-and-flush, because the channel already runs inside
 * a queued job per notification and a user rarely has more than a handful of devices; batching across
 * notifications would need a store of its own for no gain. The library is built lazily so that merely
 * resolving the transport in a request that never pushes costs nothing.
 */
class LibraryTransport implements WebPushTransport
{
    private ?Library $library = null;

    public function send(DeviceToken $token, string $payload): WebPushOutcome
    {
        // Checked again here, by resolved address: the name was public when registered, and may not be now.
        if (! PushEndpoints::resolvesPublicly($token->endpoint)) {
            Log::warning('Web push refused: endpoint is not a public push service', ['endpoint' => $token->endpoint]);

            return WebPushOutcome::Expired;
        }

        try {
            $report = $this->library()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $token->endpoint,
                    'keys' => ['p256dh' => $token->p256dh, 'auth' => $token->auth],
                    'contentEncoding' => $token->content_encoding ?: 'aes128gcm',
                ]),
                $payload,
                ['TTL' => (int) config('webpush.ttl', 86400)],
            );
        } catch (Throwable $e) {
            Log::warning('Web push failed', ['endpoint' => $token->endpoint, 'error' => $e->getMessage()]);

            return WebPushOutcome::Failed;
        }

        if ($report->isSuccess()) {
            return WebPushOutcome::Sent;
        }

        if ($report->isSubscriptionExpired()) {
            return WebPushOutcome::Expired;
        }

        Log::warning('Web push refused', ['endpoint' => $token->endpoint, 'reason' => $report->getReason()]);

        return WebPushOutcome::Failed;
    }

    private function library(): Library
    {
        // No redirects: a public endpoint answering 302 to a private address would otherwise be followed.
        return $this->library ??= new Library(WebPush::auth(), [], 30, ['allow_redirects' => false]);
    }
}
