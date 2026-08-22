<?php

namespace App\Modules\Campaigns\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Permission to contact somebody, as a row.
 *
 * **Not a checkbox, and this is the whole design.** docs/crms-plan.md §6: "who agreed to this
 * and when" is the only defensible answer when somebody complains, and an `is_subscribed`
 * boolean cannot answer it. So every grant and every revocation is its own row — somebody
 * opting in, out and in again leaves three, and the history is the answer.
 *
 * The current state is the LATEST row for that subject and channel. Deliberately derived
 * rather than cached: a cached flag would be a second answer to the same question, and the
 * moment the two disagreed the cached one would be the one that got read.
 */
class Consent extends Model
{
    use Auditable;

    public const STATE_GRANTED = 'granted';

    public const STATE_REVOKED = 'revoked';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    protected $fillable = [
        'subject_type', 'subject_id', 'channel', 'state', 'source', 'recorded_at', 'recorded_by',
    ];

    protected $casts = ['recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $consent): void {
            $consent->recorded_at ??= now();
            $consent->recorded_by ??= auth()->id();
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Whether this subject may be contacted on this channel, right now.
     *
     * **No row means NO.** Silence is not consent: a prospect nobody has asked has not agreed,
     * and defaulting to permitted would make the whole table decorative.
     */
    public static function permits(EloquentModel $subject, string $channel): bool
    {
        $latest = static::query()
            ->where('subject_type', \App\Support\ModuleMap::alias($subject::class))
            ->where('subject_id', $subject->getKey())
            ->where('channel', $channel)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        return $latest?->state === self::STATE_GRANTED;
    }

    /** Record a grant. A new row, never an update — the history is the point. */
    public static function grant(EloquentModel $subject, string $channel, ?string $source = null): self
    {
        return static::create([
            'subject_type' => \App\Support\ModuleMap::alias($subject::class),
            'subject_id' => $subject->getKey(),
            'channel' => $channel,
            'state' => self::STATE_GRANTED,
            'source' => $source,
        ]);
    }

    /**
     * Record a revocation.
     *
     * **A new row, never flipping the old one.** §6 is explicit, and the reason is practical:
     * an unsubscribe that overwrote the grant would destroy the evidence that consent was ever
     * given — which is exactly what a complaint asks about.
     */
    public static function revoke(EloquentModel $subject, string $channel, ?string $source = null): self
    {
        return static::create([
            'subject_type' => \App\Support\ModuleMap::alias($subject::class),
            'subject_id' => $subject->getKey(),
            'channel' => $channel,
            'state' => self::STATE_REVOKED,
            'source' => $source,
        ]);
    }
}
