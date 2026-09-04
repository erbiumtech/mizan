<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\User;
use App\Notifications\RecordChanged;
use App\Support\RecordAudience;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns the audit trail into the bell.
 *
 * **The audit trail is already the answer to "when anything updates".** Every model that
 * matters uses `App\Traits\Auditable`, so 157 of them already write a row saying what
 * changed, who changed it and on which record. Instrumenting them one at a time to send a
 * notification would be 157 edits to reproduce a fact the application already has. So this
 * hangs off the `ActivityLog` row instead — one listener, and a model added tomorrow is
 * covered the day it is audited.
 *
 * **Updates and deletions, not creations.** A creation's audience is almost always the
 * person who just created it — who is excluded anyway — and creations are what an import
 * makes five thousand of. Excluding them costs nothing anybody wanted and takes the
 * blast radius of a bulk load to zero.
 *
 * **Nothing here throws.** It runs inside somebody else's save: a notification that fails
 * must not take down the write that prompted it. Every step that can fail is guarded, and
 * a failure means one missing bell entry rather than a lost record.
 *
 * ponytail: one extra read per audited update, to fetch the row the audience columns are
 * on. Fine at this scale — the alternative is denormalising the owner onto the activity
 * row, which is worth doing only if a bulk update ever shows up in the query budget.
 *
 * ponytail: sent as the activity row is written, so a write that is later rolled back can
 * leave a bell entry for a change that did not happen. The entry is informational and its
 * link opens the unchanged record, so the cost is one confusing line rather than a wrong
 * figure. `DB::afterCommit()` is the upgrade if that ever bites — it runs immediately
 * outside a transaction and defers inside one, which is exactly the behaviour wanted.
 */
class NotifyRecordAudience
{
    /** The events worth a notification. See the class docblock for why `created` is absent. */
    private const EVENTS = ['updated', 'deleted'];

    public function __invoke(ActivityLog $activity): void
    {
        try {
            $this->handle($activity);
        } catch (Throwable) {
            // Deliberately silent: see the class docblock. The change itself is already
            // written and audited, which is the part that had to be true.
        }
    }

    private function handle(ActivityLog $activity): void
    {
        if (! setting('notifications.record_changes', true)) {
            return;
        }

        if (! in_array($activity->event, self::EVENTS, true)) {
            return;
        }

        $subject = $activity->subject;

        // A delete leaves no row to read the owner from, so the audit entry's own copy of
        // the attributes as they were is what names them.
        $old = (array) (($activity->properties['old'] ?? null) ?: []);

        $audience = RecordAudience::for($subject, $activity->causer_id, $old);

        if ($audience === []) {
            return;
        }

        $users = User::query()->whereKey($audience)->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new RecordChanged(
            title: $this->title($activity, $subject),
            body: $this->body($activity),
            url: $this->url($subject),
            isDeletion: $activity->event === 'deleted',
        ));
    }

    /** "Ticket #14 updated", falling back to the model's own name when no resource claims it. */
    private function title(ActivityLog $activity, ?Model $subject): string
    {
        $label = Str::headline(class_basename($activity->subject_type ?: 'record'));
        $name = null;

        if ($subject !== null && ($resource = $this->resourceFor($subject)) !== null) {
            $label = Str::headline($resource::getModelLabel());
            $name = (string) ($resource::getRecordTitle($subject) ?? '');
        }

        $name = $name !== null && $name !== '' ? $name : ($subject?->getKey() === null ? '' : '#'.$subject->getKey());

        return trim($label.' '.$name).' '.($activity->event === 'deleted' ? 'deleted' : 'updated');
    }

    /** Who did it, and which fields moved — the two things worth reading in a line. */
    private function body(ActivityLog $activity): string
    {
        $who = $activity->causer?->name ?? 'The system';

        $changed = array_keys((array) (($activity->properties['attributes'] ?? null) ?: []));

        if ($changed === [] || $activity->event === 'deleted') {
            return $activity->event === 'deleted'
                ? $who.' deleted it.'
                : $who.' made a change.';
        }

        $fields = array_map(fn (string $field): string => Str::headline($field), array_slice($changed, 0, 4));
        $more = count($changed) - count($fields);

        return $who.' changed '.implode(', ', $fields).($more > 0 ? " and {$more} more" : '').'.';
    }

    /** Where to open it, when a resource in this panel owns the model. */
    private function url(?Model $subject): ?string
    {
        if ($subject === null || ($resource = $this->resourceFor($subject)) === null) {
            return null;
        }

        foreach (['view', 'edit', 'index'] as $page) {
            try {
                if (! array_key_exists($page, $resource::getPages())) {
                    continue;
                }

                return $page === 'index'
                    ? $resource::getUrl('index')
                    : $resource::getUrl($page, ['record' => $subject->getKey()]);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * The resource whose model this is.
     *
     * Only inside a panel: this runs from console commands and queued jobs too, where
     * `Filament::getResources()` has no panel to read and a URL would be meaningless.
     *
     * @return class-string<\Filament\Resources\Resource>|null
     */
    private function resourceFor(Model $subject): ?string
    {
        try {
            if (Filament::getCurrentPanel() === null) {
                return null;
            }

            foreach (Filament::getResources() as $resource) {
                if ($resource::getModel() === $subject::class) {
                    return $resource;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
