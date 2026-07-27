<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run of consecutive failures on one environment. Doubles as the
 * flap-suppression state (an incident is only "confirmed" once the failure
 * threshold is crossed), the alert dedupe key, and the downtime record.
 */
class ProjectEnvironmentIncident extends Model
{
    protected $fillable = [
        'project_environment_id', 'started_at', 'confirmed_at', 'resolved_at',
        'failure_count', 'last_error', 'last_status_code', 'reminders_sent', 'last_reminder_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'project_environment_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmed_at');
    }

    /** Human duration of the outage so far (or its full length once resolved). */
    public function durationForHumans(): string
    {
        return $this->started_at->diffForHumans(
            $this->resolved_at ?? now(),
            ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]
        );
    }
}
