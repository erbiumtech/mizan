<?php

namespace App\Modules\Work\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * One line in a Slack channel — docs/project-management-plan.md §8.7.
 *
 * Through the Slack notification channel this application already has, routed to a channel rather
 * than a person (`Notification::route('slack', …)`), and guarded by `Broadcasting::channels()` in
 * the caller: nothing is queued for a bot token that is not there. No mail, so no category and no
 * template — this is not a message to a person.
 */
class WorkChannelPost extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $text, public ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)->text($this->url !== null ? $this->text.' — '.$this->url : $this->text);
    }
}
