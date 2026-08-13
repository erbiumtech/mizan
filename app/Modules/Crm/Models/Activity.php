<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something that happened: a call, a meeting, an email.
 *
 * **Not a comment.** A comment is a discussion thread on a record; this is a dated event
 * with a duration and an outcome — "a call at 14:20 that lasted nine minutes and ended in
 * send pricing". Comments stay available on the same records for internal discussion, and
 * §3 records the decision not to press them into this job.
 *
 * Polymorphic over Lead, Contact and Opportunity. Immutable in practice: history does not
 * change, and correcting it means adding the correction rather than editing the past.
 */
class Activity extends Model
{
    use Auditable;

    public const KIND_CALL = 'call';

    public const KIND_MEETING = 'meeting';

    public const KIND_EMAIL = 'email';

    public const KIND_WHATSAPP = 'whatsapp';

    public const KIND_NOTE = 'note';

    /** @var array<int, string> */
    public const KINDS = [self::KIND_CALL, self::KIND_MEETING, self::KIND_EMAIL, self::KIND_WHATSAPP, self::KIND_NOTE];

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'subject_type', 'subject_id', 'kind', 'direction', 'subject_line', 'body',
        'occurred_at', 'duration_minutes', 'outcome', 'employee_id', 'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $activity->occurred_at ??= now();
            $activity->created_by ??= auth()->id();

            // Defaults to whoever is logging it, so the activity report — a management
            // number that must be read as effort, not performance — is attributable
            // without asking every time.
            if ($activity->employee_id === null && modules()->enabled('employees')) {
                $activity->employee_id = Employee::where('user_id', auth()->id())->value('id');
            }
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** Calls and meetings: what the activity report counts as contact. */
    public function scopeContact(Builder $query): Builder
    {
        return $query->whereIn('kind', [self::KIND_CALL, self::KIND_MEETING]);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }
}
