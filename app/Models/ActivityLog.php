<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Tenant-aware activity log. The `activity_log` table lives in the landlord
 * database (shared across companies), so each entry is tagged with the current
 * company id on write, and reads are scoped to the current company when one is
 * active. When no tenant is current (landlord/CLI context) nothing is scoped.
 */
class ActivityLog extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if ($activity->company_id === null) {
                $activity->company_id = Company::current()?->getKey();
            }
        });

        static::addGlobalScope('tenant', function (Builder $query): void {
            if ($companyId = Company::current()?->getKey()) {
                $query->where($query->getModel()->getTable().'.company_id', $companyId);
            }
        });
    }
}
