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
     * A notification's channels, less `broadcast` when there is nothing to push to.
     *
     * Called from `via()`, which is where Laravel decides what to *queue*: one `SendQueuedNotifications` job
     * per channel, before anything is sent. Filtering here means no broadcast job exists at all on an
     * installation without a socket. The `NotificationSending` listener still stands behind this for any
     * notification that forgets to call it — but that fires inside the job, after it has been queued and run.
     *
     * Only `broadcast` is touched, and the order of the rest is kept.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    public static function channels(array $channels): array
    {
        if (self::isConfigured()) {
            return $channels;
        }

        return array_values(array_filter($channels, fn (string $channel): bool => $channel !== 'broadcast'));
    }

    public static function isConfigured(): bool
    {
        $driver = config('broadcasting.default');

        return $driver !== null
            && $driver !== 'null'
            && filled(config('filament.broadcasting.echo.key'));
    }
}
