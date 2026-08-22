<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A toolbox talk — `docs/construction-management-plan.md` §17.5.
 *
 * **`delivered_at` is a datetime**, because a talk given at seven in the morning before the shift and one given at four
 * in the afternoon are different facts about a site — and the second is usually a talk given to a tick-box.
 *
 * **Attendance is a list of rows, each of which may be a register entry or just a name.** Both, because the useful
 * *report* is "who on this site has had the working-at-height talk", which needs the register, while the useful *form* is
 * one a foreman can fill in at seven in the morning for a gang half of whom arrived that day.
 *
 * §17.6 counts talks delivered *and attended*, which is why the attendee count is the figure this model is asked for
 * rather than the number of talks: forty talks to two people each is not a briefed site.
 */
class ToolboxTalk extends Model
{
    use Auditable;

    protected $table = 'construction_toolbox_talks';

    protected $fillable = [
        'job_id', 'reference', 'topic', 'content_summary', 'delivered_at', 'delivered_by', 'presenter_label',
        'location_id', 'duration_minutes', 'prompted_by', 'incident_id', 'notes',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $talk): void {
            $talk->delivered_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** The incident that prompted it, where one did — the one link worth being able to query. */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(ToolboxTalkAttendee::class, 'toolbox_talk_id')->orderBy('name');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeDeliveredBetween(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->where('delivered_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('delivered_at', '<=', Carbon::parse($to)->endOfDay());
    }

    /** Raised in response to something rather than as a routine. */
    public function wasPrompted(): bool
    {
        return filled($this->prompted_by) || $this->incident_id !== null;
    }

    /** How many were there — the figure §17.6 counts, rather than the number of talks. */
    public function attendeeCount(): int
    {
        return $this->attendees->count();
    }

    /** How many actually signed, which is the difference between a record and a list of names. */
    public function signedCount(): int
    {
        return $this->attendees->filter(fn (ToolboxTalkAttendee $a): bool => $a->signed)->count();
    }

    /**
     * A talk with nobody recorded against it.
     *
     * Named rather than treated as zero attendance: a talk that was given and not written up is a different fact from a
     * talk nobody came to, and only the first is worth chasing.
     */
    public function hasNoAttendees(): bool
    {
        return $this->attendees->isEmpty();
    }

    public function displayName(): string
    {
        return "{$this->reference} — {$this->topic}";
    }
}
