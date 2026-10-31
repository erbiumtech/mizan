<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Support\Integrations;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;

/**
 * A company's door to one provider account — docs/project-management-plan.md §8.1.
 *
 * Holds the credential, encrypted with the same cast the environment passwords use, and the
 * circuit breaker of §8.6: five consecutive failures pause it for ten minutes, and a paused
 * connection queues nothing inbound either.
 */
class IntegrationConnection extends Model
{
    use Auditable;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const DISCONNECTED = 'disconnected';

    public const AUTH_APP = 'app';

    public const AUTH_OAUTH = 'oauth';

    public const AUTH_TOKEN = 'token';

    public const AUTH_KINDS = [
        self::AUTH_APP => 'App installation',
        self::AUTH_OAUTH => 'OAuth',
        self::AUTH_TOKEN => 'Personal access token',
    ];

    /** How many failures in a row pause the connection, and for how long — §8.6. */
    public const FAILURES_BEFORE_PAUSE = 5;

    public const PAUSE_MINUTES = 10;

    protected $fillable = [
        'provider', 'kind', 'label', 'auth_kind', 'credentials', 'scopes', 'external_account_id',
        'external_account_name', 'base_url', 'connected_by', 'connected_at', 'status', 'paused_until',
        'consecutive_failures', 'last_failure', 'last_event_at', 'webhook_secret',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'scopes' => 'array',
        'connected_at' => 'datetime',
        'paused_until' => 'datetime',
        'last_event_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    // The secret is never on a screen. The credentials are encrypted at rest and the form shows the
    // installation id and never the token back — see IntegrationConnectionResource.
    protected $hidden = ['webhook_secret'];

    protected $attributes = ['status' => self::ACTIVE, 'auth_kind' => self::AUTH_TOKEN];

    /**
     * `Auditable` logs every fillable attribute, which would copy the decrypted credentials and the
     * signing secret into a second table the encryption does not cover — and leave every rotated
     * token behind in the log. Excluded, the way `ProjectEnvironment` excludes its password.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['credentials', 'webhook_secret', 'consecutive_failures', 'last_failure', 'last_event_at', 'paused_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename(static::class));
    }

    protected static function booted(): void
    {
        static::creating(function (self $connection): void {
            $connection->webhook_secret ??= Str::random(48);
            $connection->kind ??= Integrations::kind($connection->provider) ?? Integrations::CODE;
            $connection->connected_at ??= now();
        });
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(WorkRepository::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(IntegrationIdentity::class);
    }

    /** A time tracker's projects linked through this connection — §8.8. */
    public function timeProjects(): HasMany
    {
        return $this->hasMany(WorkTimeProject::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function providerLabel(): string
    {
        return Integrations::label($this->provider);
    }

    /** One credential by name — `token`, `installation_id`, `refresh_token`. */
    public function credential(string $name): ?string
    {
        $value = ($this->credentials ?? [])[$name] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Whether calls may be made and deliveries accepted right now.
     *
     * A pause expires by itself: ten minutes after the fifth failure the next call is allowed to
     * try, and a success clears the count. Disconnected does not expire.
     */
    public function isOpen(): bool
    {
        if ($this->status === self::DISCONNECTED) {
            return false;
        }

        if ($this->status === self::PAUSED) {
            return $this->paused_until !== null && $this->paused_until->isPast();
        }

        return true;
    }

    /** A call to the provider failed. The fifth in a row pauses the connection — §8.6. */
    public function recordFailure(string $reason): void
    {
        $failures = $this->consecutive_failures + 1;

        $this->forceFill([
            'consecutive_failures' => $failures,
            'last_failure' => Str::limit($reason, 250),
            ...($failures >= self::FAILURES_BEFORE_PAUSE ? [
                'status' => self::PAUSED,
                'paused_until' => now()->addMinutes(self::PAUSE_MINUTES),
            ] : []),
        ])->save();
    }

    /** A call succeeded: the count goes back to nought and a pause ends. */
    public function recordSuccess(): void
    {
        if ($this->consecutive_failures === 0 && $this->status === self::ACTIVE) {
            return;
        }

        $this->forceFill([
            'consecutive_failures' => 0,
            'last_failure' => null,
            'status' => $this->status === self::DISCONNECTED ? self::DISCONNECTED : self::ACTIVE,
            'paused_until' => null,
        ])->save();
    }

    /**
     * How many deliveries are waiting — what the connection page says.
     *
     * Two kinds, and the page's helper text names both: received and not yet processed (queued), and
     * failed with a reason a person can fix — an unlinked project, an unmatched user — after which a
     * replay lands them. The second is the common case: a held entry is recorded as a failure with its
     * reason, so counting only the queued ones would show 0 while four entries sat waiting on a link.
     */
    public function eventsWaitingOnIdentity(): int
    {
        return $this->events()
            ->whereNull('processed_at')
            ->where(fn ($query) => $query->whereNull('failed_at')->orWhere('failure', 'like', '%held until%'))
            ->count();
    }
}
