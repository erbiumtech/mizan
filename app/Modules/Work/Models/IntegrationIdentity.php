<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a provider username is here — docs/project-management-plan.md §8.5.
 *
 * Matched by verified email on first sight; failing that, listed as unmatched until somebody
 * claims it or an administrator assigns it. `user_id` is a landlord user id and not a relation,
 * for the reason `WorkItem::watcherIds()` gives: users live in the landlord database.
 */
class IntegrationIdentity extends Model
{
    use Auditable;

    public const BY_EMAIL = 'email';

    public const BY_MANUAL = 'manual';

    public const BY_CLAIM = 'claimed';

    protected $fillable = [
        'integration_connection_id', 'provider_username', 'provider_user_id', 'email', 'user_id', 'matched_how',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    public function isMatched(): bool
    {
        return $this->user_id !== null;
    }

    /** The user, read from the landlord database. */
    public function user(): ?User
    {
        return $this->user_id === null ? null : User::query()->find($this->user_id);
    }

    public function displayLabel(): string
    {
        return '@'.$this->provider_username.($this->email ? ' <'.$this->email.'>' : '');
    }
}
