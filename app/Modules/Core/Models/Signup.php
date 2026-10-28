<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company that does not exist yet — docs/saas-launch-plan.md §5.
 *
 * Landlord, like the user and the company it sits between. One row per signup;
 * `company_id` is written by the provisioning job and is what the "preparing your
 * workspace" page polls for. `failed_at` is the other way the wait ends.
 */
class Signup extends Model
{
    protected $fillable = [
        'user_id', 'company_name', 'profile', 'provisioning_started_at', 'company_id', 'failed_at', 'failure',
    ];

    protected $casts = [
        'provisioning_started_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Verified, provisioned or failed — nothing left for this row to wait on. */
    public function isPending(): bool
    {
        return $this->company_id === null && $this->failed_at === null;
    }
}
