<?php

namespace App\Notifications\Channels;

use App\Modules\Core\Models\DeviceToken;
use App\Support\Contracts\WebPushTransport;
use App\Support\WebPushOutcome;
use Filament\Facades\Filament;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;

/**
 * Pushes a notification to every browser the recipient has subscribed — docs/desktop-mobile-plan.md A3.
 *
 * **Every notification inherits it for free.** The channel is appended by `Broadcasting::channels()`
 * wherever a notification asks for `broadcast`, and the payload is derived from the same Filament message
 * the bell shows (`toDatabase`, or `toBroadcast`'s data), so a push and its bell entry can never say two
 * different things. A notification that wants a different wording on the lock screen defines
 * `toWebPush($notifiable)` and returns the title/body/url itself.
 *
 * Expired subscriptions are deleted here: the push service is the only party that knows a browser has
 * gone, and it says so by refusing — keeping the row would mean a failed delivery per notification forever.
 */
class WebPushChannel
{
    public function __construct(private readonly WebPushTransport $transport) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $payload = $this->payload($notifiable, $notification);

        if ($payload === null) {
            return;
        }

        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($notifiable->deviceTokens()->where('transport', DeviceToken::TRANSPORT_WEBPUSH)->get() as $token) {
            match ($this->transport->send($token, $json)) {
                WebPushOutcome::Sent => $token->forceFill(['last_used_at' => now()])->saveQuietly(),
                WebPushOutcome::Expired => $token->delete(),
                WebPushOutcome::Failed => null,
            };
        }
    }

    /**
     * The lock-screen shape: title, body, the screen to open, and a tag so a re-sent notification replaces
     * rather than stacks. Null when the notification has nothing to show, which is a notification that was
     * never meant for the bell either.
     *
     * @return array{title: string, body: string, url: string, tag: string, icon: string}|null
     */
    public function payload(object $notifiable, Notification $notification): ?array
    {
        if (method_exists($notification, 'toWebPush')) {
            $custom = $notification->toWebPush($notifiable);

            return is_array($custom) ? $this->normalise($custom, $notification) : null;
        }

        $data = match (true) {
            method_exists($notification, 'toDatabase') => $notification->toDatabase($notifiable),
            method_exists($notification, 'toBroadcast') => $notification->toBroadcast($notifiable)->data,
            method_exists($notification, 'toArray') => $notification->toArray($notifiable),
            default => null,
        };

        if (! is_array($data) || blank($data['title'] ?? null)) {
            return null;
        }

        // The bell's first action is where a tap should land; without one, the panel itself.
        $url = Arr::first(array_filter(array_map(fn ($action) => $action['url'] ?? null, $data['actions'] ?? [])));

        return $this->normalise([
            'title' => $data['title'],
            'body' => $data['body'] ?? '',
            'url' => $url,
        ], $notification);
    }

    /** @param  array<string, mixed>  $payload */
    private function normalise(array $payload, Notification $notification): array
    {
        $panel = rescue(fn (): string => Filament::getPanel('admin')->getUrl() ?? '/', '/', report: false);

        return [
            'title' => trim(strip_tags((string) $payload['title'])),
            'body' => trim(strip_tags((string) ($payload['body'] ?? ''))),
            'url' => (string) ($payload['url'] ?: $panel),
            'tag' => (string) ($payload['tag'] ?? ($notification->id ?? class_basename($notification))),
            'icon' => (string) ($payload['icon'] ?? asset('images/logo-mark.png')),
        ];
    }
}
