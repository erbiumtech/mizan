<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound delivery, kept whole — docs/project-management-plan.md §8.3.
 *
 * Verified before it was written and queued after; unique on the provider's own event id, which
 * is what makes a redelivery a no-op. A failed one keeps its payload, so it can be replayed once
 * whatever failed is fixed — an identity matched, a bug shipped.
 */
class IntegrationEvent extends Model
{
    protected $fillable = [
        'integration_connection_id', 'provider_event_id', 'type', 'payload', 'received_at',
        'processed_at', 'failed_at', 'failure',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at')->whereNull('failed_at');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
