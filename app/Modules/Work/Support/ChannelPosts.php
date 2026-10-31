<?php

namespace App\Modules\Work\Support;

use App\Modules\Work\Events\ReleaseReleased;
use App\Modules\Work\Events\SprintCompleted;
use App\Modules\Work\Events\SprintStarted;
use App\Modules\Work\Events\WorkItemCreated;
use App\Modules\Work\Events\WorkItemResolved;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Models\WorkChannelSubscription;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Notifications\WorkChannelPost;
use App\Support\Broadcasting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The six lines a channel may hear — docs/project-management-plan.md §8.7.
 *
 * Listens on the same domain events the webhooks ride, and posts one line per subscription that
 * asked for that event. Slack goes through the notification channel `Broadcasting` already guards
 * — nothing is queued for a bot token that is not configured — and Teams through its incoming
 * webhook URL with a plain card. Both one-way, and neither may throw: a chat configuration cannot
 * be the reason an item fails to save.
 */
class ChannelPosts
{
    public static function register(): void
    {
        Event::listen(WorkItemCreated::class, fn (WorkItemCreated $e) => self::post($e->item->project_id, 'item.created',
            $e->item->key.' filed: '.$e->item->title, WorkItemResource::getUrl('edit', ['record' => $e->item->getKey()])));

        Event::listen(WorkItemResolved::class, fn (WorkItemResolved $e) => self::post($e->item->project_id, 'item.done',
            $e->item->key.' done: '.$e->item->title.($e->item->resolution ? ' ('.$e->item->resolution.')' : ''), WorkItemResource::getUrl('edit', ['record' => $e->item->getKey()])));

        WorkItemLink::created(function (WorkItemLink $link): void {
            if ($link->kind !== WorkItemLink::BLOCKS) {
                return;
            }

            $blocked = $link->to()->first();
            $blocker = $link->from()->first();

            if ($blocked !== null) {
                self::post($blocked->project_id, 'item.blocked', $blocked->key.' is blocked'.($blocker ? ' by '.$blocker->key : '').': '.$blocked->title,
                    WorkItemResource::getUrl('edit', ['record' => $blocked->getKey()]));
            }
        });

        Event::listen(SprintStarted::class, fn (SprintStarted $e) => self::post($e->sprint->project_id, 'sprint.started',
            $e->sprint->name.' started'.($e->sprint->goal ? ': '.$e->sprint->goal : '').($e->sprint->ends_on ? ' — ends '.$e->sprint->ends_on->format('d M') : '')));

        Event::listen(SprintCompleted::class, fn (SprintCompleted $e) => self::post($e->sprint->project_id, 'sprint.completed',
            $e->sprint->name.' completed'.($e->movedTo ? '; unfinished work moved to '.$e->movedTo->name : '; unfinished work went back to the backlog')));

        Event::listen(ReleaseReleased::class, fn (ReleaseReleased $e) => self::post($e->release->project_id, 'release.released',
            $e->release->name.' released'.($e->release->project ? ' on '.$e->release->project->name : '')));
    }

    /** One line to every active subscription of the project that asked for this event. */
    public static function post(int|string|null $projectId, string $event, string $text, ?string $url = null): void
    {
        if ($projectId === null) {
            return;
        }

        try {
            $subscriptions = WorkChannelSubscription::query()->active()->where('project_id', $projectId)->get()
                ->filter(fn (WorkChannelSubscription $s): bool => $s->wants($event));

            foreach ($subscriptions as $subscription) {
                self::deliver($subscription, $text, $url);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function deliver(WorkChannelSubscription $subscription, string $text, ?string $url): void
    {
        try {
            if ($subscription->kind === WorkChannelSubscription::SLACK) {
                // The same guard every Slack message here passes: no bot token, nothing queued.
                if (! in_array('slack', Broadcasting::channels(['slack']), true)) {
                    return;
                }

                Notification::route('slack', $subscription->destination)->notify(new WorkChannelPost($text, $url));
            } else {
                // Checked here as well as on the form: a row written any other way must not become
                // a request to an address of the writer's choosing.
                if (! WorkChannelSubscription::isAllowedTeamsDestination($subscription->destination)) {
                    throw new \RuntimeException('The destination is not a Teams webhook host.');
                }

                $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->post($subscription->destination, [
                    '@type' => 'MessageCard',
                    '@context' => 'https://schema.org/extensions',
                    'summary' => $text,
                    'text' => $url !== null ? $text.' — ['.__('Open').']('.$url.')' : $text,
                ]);

                if ($response->failed()) {
                    throw new \RuntimeException('Teams answered '.$response->status());
                }
            }

            $subscription->forceFill(['last_posted_at' => now(), 'last_failure' => null])->saveQuietly();
        } catch (Throwable $e) {
            $subscription->forceFill(['last_failure' => mb_substr($e->getMessage(), 0, 250)])->saveQuietly();
            report($e);
        }
    }
}
