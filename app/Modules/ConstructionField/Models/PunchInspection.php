<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One re-inspection attempt — `docs/construction-management-plan.md` §16.4.
 *
 * **A row per attempt, because a `closed_at` column records only the destination.** §16.4: "closed after three failed
 * re-inspections is a different fact from closed first time, and a single closed-at column loses it — the same reasoning
 * `tickets.reopened_count` already gives in its own migration: counted rather than inferred from status history."
 *
 * The difference is money. Three visits to one snag is two site attendances nobody planned, and on a subcontractor's
 * defect it is the evidence behind a back charge. Inferring it from status history would mean trusting that nobody ever
 * corrected a status by hand.
 *
 * **`partial` is the third result and it earns its place.** A snag half put right is the commonest outcome of a first
 * re-inspection and the one that decides whether a second visit is needed. Recording it as a failure loses the
 * progress; recording it as a pass closes an item that is not done.
 */
class PunchInspection extends Model
{
    public const RESULT_PASSED = 'passed';

    public const RESULT_FAILED = 'failed';

    public const RESULT_PARTIAL = 'partial';

    /** @var array<string, string> */
    public const RESULTS = [
        self::RESULT_PASSED => 'Passed',
        self::RESULT_FAILED => 'Failed',
        self::RESULT_PARTIAL => 'Partly done',
    ];

    protected $table = 'construction_punch_inspections';

    protected $fillable = [
        'punch_item_id', 'attempt', 'inspected_on', 'inspected_by', 'inspector_contact_id',
        'result', 'notes',
    ];

    protected $casts = [
        'inspected_on' => 'date',
        'attempt' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $inspection): void {
            $inspection->inspected_by ??= auth()->id();
        });
    }

    public function punchItem(): BelongsTo
    {
        return $this->belongsTo(PunchItem::class, 'punch_item_id');
    }

    /** Who looked at it, where they have a contact record. Guarded like every other contact in this module. */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'inspector_contact_id');
    }

    public function passed(): bool
    {
        return $this->result === self::RESULT_PASSED;
    }

    /** Partly done is not a pass: the item stays open and somebody comes back. */
    public function sendsItBack(): bool
    {
        return in_array($this->result, [self::RESULT_FAILED, self::RESULT_PARTIAL], true);
    }

    public function inspectorName(): string
    {
        return (modules()->enabled('invoicing') ? $this->inspector?->name : null) ?? 'Recorded internally';
    }

    public function resultLabel(): string
    {
        return self::RESULTS[$this->result] ?? $this->result;
    }
}
