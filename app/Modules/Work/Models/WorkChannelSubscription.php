<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project posting a line per event to a chat channel — docs/project-management-plan.md §8.7.
 *
 * One-way, deliberately. Slash commands and bots are §17.6, and the day they arrive they will be
 * a provider rather than a subscription.
 */
class WorkChannelSubscription extends Model
{
    use Auditable;

    public const SLACK = 'slack';

    public const TEAMS = 'teams';

    public const KINDS = [
        self::SLACK => 'Slack channel',
        self::TEAMS => 'Microsoft Teams incoming webhook',
    ];

    /** The six events a channel may hear about, as the plan lists them. */
    public const EVENTS = [
        'item.created' => 'An item was filed',
        'item.done' => 'An item was finished',
        'item.blocked' => 'An item became blocked',
        'sprint.started' => 'A sprint started',
        'sprint.completed' => 'A sprint was completed',
        'release.released' => 'A release went out',
    ];

    protected $fillable = ['project_id', 'kind', 'destination', 'events', 'is_active', 'last_posted_at', 'last_failure'];

    protected $casts = [
        'destination' => 'encrypted',
        'events' => 'array',
        'is_active' => 'boolean',
        'last_posted_at' => 'datetime',
    ];

    protected $attributes = ['is_active' => true];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function wants(string $event): bool
    {
        return $this->is_active && in_array($event, (array) $this->events, true);
    }

    /** What the row shows, without giving a Teams URL away in full. */
    public function destinationLabel(): string
    {
        if ($this->kind === self::SLACK) {
            return '#'.ltrim((string) $this->destination, '#');
        }

        $host = parse_url((string) $this->destination, PHP_URL_HOST) ?: 'webhook';

        return $host.'/…';
    }
}
