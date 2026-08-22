<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A witness to an incident — `docs/construction-management-plan.md` §17.3.
 *
 * **The name works alone.** A witness on a construction site is usually somebody else's employee, and a register that
 * required an employee record would record the witnesses who happened to be on the payroll — which is not the same set
 * as the witnesses.
 *
 * **`statement_taken_on` is its own column, and it is the point of the row.** A statement taken on the day is worth a
 * multiple of one taken three weeks later, and only a date makes the difference visible. An investigation that cannot
 * say when it spoke to somebody is an investigation nobody can weigh.
 */
class IncidentWitness extends Model
{
    protected $table = 'construction_incident_witnesses';

    protected $fillable = [
        'incident_id', 'name', 'employer', 'contact_detail', 'employee_id',
        'statement', 'statement_taken_on', 'taken_by',
    ];

    protected $casts = [
        'statement_taken_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $witness): void {
            if (filled($witness->statement) && $witness->statement_taken_on === null) {
                // Dated on entry where somebody typed a statement without one: an undated statement is the thing this
                // column exists to prevent, and defaulting is better than storing the gap.
                $witness->statement_taken_on = now()->toDateString();
                $witness->taken_by ??= auth()->id();
            }
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function hasStatement(): bool
    {
        return filled($this->statement);
    }

    /**
     * How long after the incident the statement was taken.
     *
     * Null with no statement or no incident date. The figure is what tells an investigator how much weight the account
     * carries — days, not a judgement.
     */
    public function daysAfterIncident(): ?int
    {
        if ($this->statement_taken_on === null) {
            return null;
        }

        $incident = $this->relationLoaded('incident') ? $this->incident : Incident::query()->find($this->incident_id);

        return $incident === null
            ? null
            : max(0, (int) $incident->occurred_at->startOfDay()->diffInDays($this->statement_taken_on, absolute: false));
    }

    public function displayName(): string
    {
        return $this->name.($this->employer ? " ({$this->employer})" : '');
    }
}
