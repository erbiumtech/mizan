<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;

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

    /** The Teams URL is the whole credential, so it never reaches the activity log. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['destination', 'last_posted_at', 'last_failure'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename(static::class));
    }

    /**
     * Hosts an incoming webhook may live on. A Teams connector URL is on `*.webhook.office.com`;
     * a Power Automate "post to Teams" flow is on `*.logic.azure.com`. Nothing else, so a
     * `BoardManage` holder cannot point the server at an internal address and have an item save
     * make the request — the SSRF the first review named.
     */
    public const TEAMS_HOST_SUFFIXES = ['.webhook.office.com', '.logic.azure.com'];

    public static function isAllowedTeamsDestination(?string $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null)) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (self::TEAMS_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

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
