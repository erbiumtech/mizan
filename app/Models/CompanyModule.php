<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company module state, in the landlord database.
 *
 * Two flags, two different owners:
 *
 *  - `licensed` — the company has bought the module. Super admins only.
 *  - `enabled`  — the company wants it visible right now. Its own Administrator.
 *
 * A module counts as available only when both are true (App\Support\Modules).
 * Revoking a licence deliberately leaves `enabled` alone, so re-granting it
 * restores the company's own choice rather than silently resetting it.
 *
 * No `$connection` is set on purpose: the default connection *is* the landlord
 * database (only `tenant` is switched per request), which is what lets
 * Modules::enabled() work in commands and queued jobs where no tenant is
 * current.
 */
class CompanyModule extends Model
{
    protected $fillable = [
        'company_id',
        'module',
        'licensed',
        'enabled',
    ];

    protected $casts = [
        'licensed' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
