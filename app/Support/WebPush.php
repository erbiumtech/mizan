<?php

namespace App\Support;

/**
 * Whether this installation can push to a browser at all — docs/desktop-mobile-plan.md A3.
 *
 * The push analogue of {@see Broadcasting}: the keys are the configuration, and without them the channel
 * is withdrawn from every notification before it becomes a job. The public key is also what the panel
 * hands the browser when it subscribes (M2), so it is read from one place.
 */
final class WebPush
{
    public const CHANNEL = 'webpush';

    public static function isConfigured(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key'));
    }

    public static function publicKey(): ?string
    {
        $key = config('webpush.public_key');

        return filled($key) ? (string) $key : null;
    }

    /**
     * The VAPID triple the library signs with.
     *
     * @return array{VAPID: array{subject: string, publicKey: string, privateKey: string}}
     */
    public static function auth(): array
    {
        $subject = (string) (config('webpush.subject') ?: ('mailto:'.config('mail.from.address')));

        return [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => (string) config('webpush.public_key'),
                'privateKey' => (string) config('webpush.private_key'),
            ],
        ];
    }
}
