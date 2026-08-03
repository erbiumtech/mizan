<?php

namespace App\Modules\Core\Models;

use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Throwable;

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

            $activity->stampImpersonator();
        });

        static::addGlobalScope('tenant', function (Builder $query): void {
            if ($companyId = Company::current()?->getKey()) {
                $query->where($query->getModel()->getTable().'.company_id', $companyId);
            }
        });
    }

    /**
     * Record who was really at the keyboard when an administrator is signed in as
     * somebody else.
     *
     * The causer stays the impersonated user, because that is whose data changed
     * and whose record it is. But without this, an administrator accepting a
     * salary change on an employee's behalf would be indistinguishable from the
     * employee accepting it — and that acknowledgement is a statement of consent.
     * Stamped here rather than at each call site so it covers every audited
     * change, including the ones written automatically by the Auditable trait.
     */
    protected function stampImpersonator(): void
    {
        // Resolved lazily and defensively: activity is logged from console
        // commands and queued jobs too, where there is no session at all.
        try {
            $impersonator = app(Impersonation::class)->impersonator();
        } catch (Throwable) {
            return;
        }

        if (! $impersonator) {
            return;
        }

        $properties = collect($this->properties ?? []);

        $this->properties = $properties->put('impersonated_by', [
            'id' => $impersonator->getKey(),
            'email' => $impersonator->email,
            'name' => $impersonator->name,
        ])->all();
    }
}
