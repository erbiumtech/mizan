<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to deliver one event — docs/saas-launch-plan.md §14.9.
 *
 * The row is written before the attempt, not after. A delivery that was never
 * attempted because the queue died has to look different from one that was never
 * recorded, and the only way to tell is for the record to come first.
 */
class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['webhook_endpoint_id', 'event', 'payload', 'status', 'attempts', 'response_code', 'error', 'delivered_at'];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'response_code' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }
}
