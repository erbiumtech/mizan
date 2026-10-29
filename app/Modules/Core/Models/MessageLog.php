<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row per message the application sent — docs/saas-launch-plan.md §14.7.
 *
 * Channel, recipient, purpose, status. Not the body: a log that keeps payslip
 * figures and personal letters is a second copy of everything sensitive, and
 * the question this answers is "did it go, and where to".
 */
class MessageLog extends Model
{
    public const UPDATED_AT = null;

    public const CHANNEL_MAIL = 'mail';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['channel', 'recipient', 'purpose', 'subject', 'status', 'reference', 'error', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function record(array $attributes): ?self
    {
        // Logging must never be the reason a message fails to send, nor a reason a
        // request 500s on a tenant whose table is not migrated yet.
        try {
            return static::create($attributes + ['sent_at' => now(), 'status' => self::STATUS_SENT]);
        } catch (\Throwable) {
            return null;
        }
    }
}
