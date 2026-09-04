<?php

namespace App\Modules\Support\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\ContactPerson;
use App\Modules\Projects\Models\Project;
use App\Support\Contracts\NotifiesOnChange;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a customer has asked for help with.
 *
 * **The SLA is measured, never enforced.** A breach is reported; nothing here refuses to
 * close a ticket because time elapsed. docs/crms-plan.md §5 gives the reason plainly — the
 * elapsed time is a fact about the past, and blocking the close makes the register wrong as
 * well as late. It is the same position §4.2 takes on overtime caps and §6 on minimum wage:
 * report it, do not quietly adjust around it.
 */
class Ticket extends Model implements NotifiesOnChange
{
    use Auditable;

    public const STATUS_NEW = 'new';

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING_CUSTOMER = 'pending_customer';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    /** @var array<int, string> */
    public const OPEN_STATUSES = [self::STATUS_NEW, self::STATUS_OPEN, self::STATUS_PENDING_CUSTOMER];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CHANNELS = ['phone', 'email', 'whatsapp', 'panel', 'in_person'];

    protected $fillable = [
        'number', 'contact_id', 'contact_person_id', 'project_id', 'category_id',
        'subject', 'description', 'channel', 'priority', 'status',
        'assignee_employee_id', 'opened_at', 'first_responded_at', 'resolved_at',
        'closed_at', 'reopened_count', 'satisfaction_rating', 'created_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_count' => 'integer',
        'satisfaction_rating' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'priority' => 'normal',
        'channel' => 'panel',
        'reopened_count' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $ticket->created_by ??= auth()->id();
            $ticket->opened_at ??= now();
            $ticket->number = $ticket->number ?: static::nextNumber();

            // The category's default priority, unless somebody has said otherwise. A category
            // exists partly to carry that default so nobody has to remember it.
            if ($ticket->category_id && ! $ticket->getAttribute('priority')) {
                $ticket->priority = $ticket->category?->default_priority ?? 'normal';
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    /** Guarded: `support` requires nothing, so a company without Invoicing leaves this null. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function contactPerson(): BelongsTo
    {
        return $this->belongsTo(ContactPerson::class, 'contact_person_id');
    }

    /** Guarded on `projects`: which engagement it is about, where there is one. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Who hears when somebody else touches this ticket: whoever it is assigned to, and whoever raised it.
     *
     * The generic rule in `App\Support\RecordAudience` would find `created_by` on its own and stop there —
     * it reads user columns only, and an assignee is an *employee*. Following that to the person's login
     * needs the Employees model, which shared code may not import and this module may. That is the whole
     * reason the contract exists; see `App\Support\Contracts\NotifiesOnChange`.
     *
     * @return array<int, int|null>
     */
    public function changeAudience(): iterable
    {
        return [
            $this->created_by,
            $this->assignee?->user_id,
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at');
    }

    /**
     * The replies a customer may see.
     *
     * Every customer-facing read goes through this rather than `replies()`. §12.13 asserts it,
     * and the flag exists in advance of the portal precisely so this is already true if the
     * portal is ever built.
     */
    public function customerVisibleReplies(): HasMany
    {
        return $this->replies()->customerVisible();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Minutes to the first response, or null if nobody has answered yet.
     *
     * Measured from `opened_at` and set once — a reopened ticket keeps its original first
     * response, because that is what the customer actually experienced.
     */
    public function minutesToFirstResponse(): ?int
    {
        return $this->first_responded_at
            ? (int) $this->opened_at->diffInMinutes($this->first_responded_at)
            : null;
    }

    public function minutesToResolution(): ?int
    {
        return $this->resolved_at
            ? (int) $this->opened_at->diffInMinutes($this->resolved_at)
            : null;
    }

    /**
     * Whether the response commitment has been missed.
     *
     * True for a ticket nobody has answered yet whose time is already up — not only for one
     * answered late. A breach that has not happened yet is the one still worth acting on, and
     * a report that only counted past failures would surface it too late to help.
     */
    public function hasBreachedResponse(): bool
    {
        $sla = $this->category?->sla_response_minutes;

        if (! $sla) {
            return false;
        }

        $elapsed = $this->minutesToFirstResponse() ?? (int) $this->opened_at->diffInMinutes(now());

        return $elapsed > $sla;
    }

    public function hasBreachedResolution(): bool
    {
        $sla = $this->category?->sla_resolution_minutes;

        if (! $sla) {
            return false;
        }

        $elapsed = $this->minutesToResolution() ?? (int) $this->opened_at->diffInMinutes(now());

        return $elapsed > $sla;
    }

    /** What to say about the SLA, in words, or null when there is nothing to say. */
    public function slaNote(): ?string
    {
        $notes = [];

        if ($this->hasBreachedResponse()) {
            $notes[] = 'first response past the '.$this->minutesLabel($this->category->sla_response_minutes);
        }

        if ($this->hasBreachedResolution()) {
            $notes[] = 'resolution past the '.$this->minutesLabel($this->category->sla_resolution_minutes);
        }

        return $notes === [] ? null : implode('; ', $notes).' — reported, not enforced';
    }

    private function minutesLabel(int $minutes): string
    {
        return $minutes >= 60
            ? round($minutes / 60, 1).'h commitment'
            : $minutes.'m commitment';
    }

    public static function nextNumber(): string
    {
        $prefix = 'T-'.now()->format('Y').'-';

        $last = static::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
