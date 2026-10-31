<?php

namespace App\Support\WebPushTransports;

use App\Modules\Core\Models\DeviceToken;
use App\Support\Contracts\WebPushTransport;
use App\Support\WebPushOutcome;

/**
 * Records what would have been pushed, for tests — and for an installation that wants push visible in
 * the log before it trusts a push service with it.
 *
 * Bound in place of the library by a test (`$this->app->instance(WebPushTransport::class, new
 * ArrayTransport)`); endpoints named in `$expired` answer Expired so the channel's clean-up can be shown.
 */
class ArrayTransport implements WebPushTransport
{
    /** @var array<int, array{endpoint: string, user_id: int, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @param  array<int, string>  $expired */
    public function __construct(public array $expired = []) {}

    public function send(DeviceToken $token, string $payload): WebPushOutcome
    {
        if (in_array($token->endpoint, $this->expired, true)) {
            return WebPushOutcome::Expired;
        }

        $this->sent[] = [
            'endpoint' => $token->endpoint,
            'user_id' => (int) $token->user_id,
            'payload' => (array) json_decode($payload, true),
        ];

        return WebPushOutcome::Sent;
    }
}
