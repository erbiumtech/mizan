<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a transmittal went to, in what capacity, and whether they acknowledged it — §15.
 *
 * `role` matters beyond bookkeeping: an *action* recipient owes a response, an *approval* recipient owes a
 * decision, and an *information* recipient owes nothing. A chase list that treated the three alike would chase
 * people who were only ever copied in.
 */
class TransmittalRecipient extends Model
{
    use Auditable;

    public const ROLE_ACTION = 'action';

    public const ROLE_INFORMATION = 'information';

    public const ROLE_APPROVAL = 'approval';

    protected $table = 'construction_transmittal_recipients';

    protected $fillable = [
        'transmittal_id', 'contact_id', 'name', 'email', 'role',
        'notified_at', 'acknowledged_at', 'acknowledged_by_name', 'chased_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'chased_at' => 'datetime',
    ];

    protected $attributes = ['role' => self::ROLE_INFORMATION];

    /**
     * Always with its contact, because a recipient is never read alone.
     *
     * Lists of these are the whole point of the table, and one query per row on the chase list is the classic
     * N+1 — cheap to avoid here and invisible until somebody has a hundred recipients on a drawing issue.
     *
     * @var array<int, string>
     */
    protected $with = ['contact'];

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class, 'transmittal_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * The contact's name, or the free-text one for a recipient who is not in the contact book.
     *
     * Reads the relation only when it is already loaded. A recipient is always rendered as part of a list — the
     * chase list, the transmittal's own panel — so lazy-loading here is one query per row on the screen a
     * document controller opens most; `$with` below is what makes it free instead.
     */
    public function displayName(): string
    {
        $contact = $this->relationLoaded('contact') ? $this->contact : null;

        return $contact?->name ?? ($this->name ?: 'Unnamed recipient');
    }

    public function hasAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** Owes a response or a decision, as against merely being copied in. */
    public function owesAResponse(): bool
    {
        return in_array($this->role, [self::ROLE_ACTION, self::ROLE_APPROVAL], true);
    }
}
