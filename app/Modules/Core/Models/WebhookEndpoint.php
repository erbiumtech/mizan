<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\Webhooks;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Somewhere a company's own systems listen — docs/saas-launch-plan.md §14.9.
 *
 * The secret is the whole security model, so it is generated here and never typed:
 * a person choosing one would choose a short one. Every delivery carries an HMAC of
 * the body under it, which is what lets the receiver tell a real delivery from
 * anybody who learned the URL.
 */
class WebhookEndpoint extends Model
{
    use Auditable;

    /** Failures in a row before the endpoint is switched off and somebody is told. */
    public const FAILURE_LIMIT = 10;

    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'consecutive_failures', 'last_delivered_at'];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'consecutive_failures' => 'integer',
        'last_delivered_at' => 'datetime',
    ];

    /** Never in an audit row, never in a disclosure, never on a screen after the first. */
    protected $hidden = ['secret'];

    protected static function booted(): void
    {
        static::creating(function (self $endpoint): void {
            $endpoint->secret ??= self::newSecret();
            // An endpoint subscribed to a key nothing fires is a subscription that will
            // never deliver, which reads as a broken integration rather than a typo.
            $endpoint->events = array_values(array_intersect((array) $endpoint->events, Webhooks::keys()));
        });

        static::updating(function (self $endpoint): void {
            $endpoint->events = array_values(array_intersect((array) $endpoint->events, Webhooks::keys()));
        });
    }

    public static function newSecret(): string
    {
        return Str::random(64);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function wants(string $event): bool
    {
        return $this->is_active && in_array($event, (array) $this->events, true);
    }

    /** @return array<int, string> the labels of what this endpoint listens for */
    public function eventLabels(): array
    {
        $options = Webhooks::options();

        return array_values(array_map(fn (string $key): string => $options[$key] ?? $key, (array) $this->events));
    }

    /** The signature a receiver should expect over this body. */
    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) $this->secret);
    }
}
