<?php

namespace App\Modules\Crm\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Support\ModuleMap;
use App\Support\WhatsApp\PhoneNumber;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A visit to a lead, a deal or a customer — docs/saas-launch-plan.md §8.5.
 *
 * A next action with a location and a witness. Check-in and check-out carry
 * the phone's coordinates; nothing judges them — a contact has an address, not
 * a point — the visit shows a map link and the reader judges. The witness is
 * the person visited, who confirms from a link sent at check-out.
 */
class FieldVisit extends Model
{
    use Auditable;

    public const OUTCOME_POSITIVE = 'positive';

    public const OUTCOME_FOLLOW_UP = 'follow_up';

    public const OUTCOME_NO_INTEREST = 'no_interest';

    public const OUTCOME_NOT_MET = 'not_met';

    public const OUTCOMES = [
        self::OUTCOME_POSITIVE => 'Positive',
        self::OUTCOME_FOLLOW_UP => 'Follow up',
        self::OUTCOME_NO_INTEREST => 'No interest',
        self::OUTCOME_NOT_MET => 'Not met',
    ];

    protected $fillable = [
        'employee_id', 'subject_type', 'subject_id', 'planned_at',
        'checked_in_at', 'in_latitude', 'in_longitude', 'in_accuracy_m',
        'checked_out_at', 'out_latitude', 'out_longitude', 'notes', 'outcome',
        'confirmation_sent_to', 'confirmed_at', 'confirmed_name', 'next_action_id', 'created_by',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'in_latitude' => 'float',
        'in_longitude' => 'float',
        'out_latitude' => 'float',
        'out_longitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $visit): void {
            $visit->created_by ??= auth()->id();
        });
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function nextAction(): BelongsTo
    {
        return $this->belongsTo(NextAction::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('checked_out_at');
    }

    public function scopeUnconfirmed(Builder $query): Builder
    {
        return $query->completed()->whereNull('confirmed_at');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('planned_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    public function isCheckedOut(): bool
    {
        return $this->checked_out_at !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** The three subjects a visit can be about, keyed by morph alias. */
    public static function subjectTypes(): array
    {
        $types = [
            ModuleMap::alias(Lead::class) => 'Lead',
            ModuleMap::alias(Opportunity::class) => 'Deal',
        ];

        if (modules()->enabled('invoicing')) {
            $types[ModuleMap::alias(Contact::class)] = 'Customer';
        }

        return $types;
    }

    public function subjectLabel(): string
    {
        $subject = $this->subject;

        return match (true) {
            $subject instanceof Lead => trim(($subject->company_name ?: $subject->person_name).($subject->company_name && $subject->person_name ? ' — '.$subject->person_name : '')),
            $subject instanceof Opportunity => $subject->title,
            $subject instanceof Contact => $subject->name,
            default => '—',
        };
    }

    /** Who is written to for the confirmation: the person the visit was with. */
    public function contactEmail(): ?string
    {
        $subject = $this->subject;

        return match (true) {
            $subject instanceof Lead => $subject->email ?: null,
            $subject instanceof Opportunity => $subject->contact?->correspondenceEmail() ?: ($subject->lead?->email ?: null),
            $subject instanceof Contact => $subject->correspondenceEmail(),
            default => null,
        };
    }

    public function contactWhatsApp(): ?string
    {
        $subject = $this->subject;

        $number = match (true) {
            $subject instanceof Lead => $subject->whatsapp ?: $subject->phone,
            $subject instanceof Opportunity => $subject->lead?->whatsapp ?: ($subject->contact?->phone ?: $subject->lead?->phone),
            $subject instanceof Contact => $subject->phone,
            default => null,
        };

        // The default country code from config/whatsapp.php, as payroll's numbers are read.
        return $number ? PhoneNumber::e164($number) : null;
    }

    public function mapLink(): ?string
    {
        if ($this->in_latitude === null || $this->in_longitude === null) {
            return null;
        }

        return 'https://www.google.com/maps?q='.$this->in_latitude.','.$this->in_longitude;
    }
}
