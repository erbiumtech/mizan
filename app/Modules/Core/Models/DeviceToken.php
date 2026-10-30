<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser or app that asked to be pushed to — docs/desktop-mobile-plan.md A3.
 *
 * Landlord, like the user it belongs to: a device is a person's, not a company's, and the same phone
 * follows them into every company they are a member of. `transport` is `webpush` today and is a column
 * rather than an assumption so FCM can sit beside it without a second table if a native shell ever
 * arrives (Phase 0 Q3/Q4). The endpoint is unique: a browser that re-subscribes replaces its own row.
 */
class DeviceToken extends Model
{
    public const TRANSPORT_WEBPUSH = 'webpush';

    protected $fillable = ['user_id', 'transport', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'content_encoding', 'user_agent', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    /** The push-service keys are credentials; they never leave the server in a response. */
    protected $hidden = ['p256dh', 'auth'];

    protected static function booted(): void
    {
        // The unique index is on this, not on the endpoint: a push-service URL can run past the length an
        // index will take, and a hash is the same for the same endpoint however long it is.
        static::saving(function (self $token): void {
            $token->endpoint_hash = hash('sha256', (string) $token->endpoint);
        });
    }

    /** A browser that subscribes again replaces its own row, whoever was signed in when it last did. */
    public static function register(User $user, string $endpoint, array $attributes): self
    {
        return static::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [...$attributes, 'user_id' => $user->getKey(), 'endpoint' => $endpoint],
        );
    }

    /** Same pin as User, for the same reason: a tenant query must not drag this onto the tenant connection. */
    public function getConnectionName(): ?string
    {
        return config('multitenancy.landlord_database_connection_name') ?: config('database.default');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What the device is called back to a client: enough to recognise it, nothing a push could be forged with. */
    public function toClient(): array
    {
        return [
            'id' => $this->getKey(),
            'transport' => $this->transport,
            'endpoint_host' => (string) parse_url($this->endpoint, PHP_URL_HOST),
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
