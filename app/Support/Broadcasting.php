<?php

namespace App\Support;

/**
 * Whether this installation has a socket to push to at all.
 *
 * Two places need the same answer and must not drift on it: the bell's polling interval (five minutes is
 * the fallback for a dropped socket, and the only path where there is none) and the broadcast channel on
 * every notification (which, with no broadcaster, fails as a job rather than delivering nothing).
 *
 * "Configured" is the honest half of the question. Whether Reverb is actually *up* cannot be asked from
 * here — that is what the failed job was reporting — so `BROADCAST_CONNECTION=null` is how an installation
 * without one says so.
 */
final class Broadcasting
{
    /**
     * A notification's channels, less the ones this installation cannot deliver on.
     *
     * Called from `via()`, which is where Laravel decides what to *queue*: one `SendQueuedNotifications` job
     * per channel, before anything is sent. Filtering here means no broadcast job exists at all on an
     * installation without a socket. The `NotificationSending` listener still stands behind this for any
     * notification that forgets to call it — but that fires inside the job, after it has been queued and run.
     *
     * `slack` is dropped the same way when no bot token is configured — SlackChannel throws a LogicException
     * without one, which would be a failed job per recipient rather than a quieter channel. One guard here
     * rather than one per notification, because every alerting `via()` already routes through this.
     *
     * Only `broadcast` and `slack` are touched, and the order of the rest is kept.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    public static function channels(array $channels): array
    {
        $undeliverable = [];

        if (! self::isConfigured()) {
            $undeliverable[] = 'broadcast';
        }

        if (! config('services.slack.notifications.bot_user_oauth_token')) {
            $undeliverable[] = 'slack';
        }

        if ($undeliverable === []) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn (string $channel): bool => ! in_array($channel, $undeliverable, true),
        ));
    }

    public static function isConfigured(): bool
    {
        $driver = config('broadcasting.default');

        return $driver !== null
            && $driver !== 'null'
            && filled(config('filament.broadcasting.echo.key'));
    }
}
