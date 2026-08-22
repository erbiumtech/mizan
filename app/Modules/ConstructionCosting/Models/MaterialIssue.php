<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Inventory\Models\StockLocation;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A docket out of a site store — `docs/construction-management-plan.md` §6.
 *
 * **"The issue document is construction's regardless."** The two names on this row are why: the paper docket has two
 * signatures — who issued and who received — and `stock_movements` is deliberately thin. §6 draws the line as "the
 * module owns the document, Inventory owns the movement", which is what `InvoiceService::recordMovement()` already does.
 *
 * **Posting an issue adds no cost.** The receipt costed the material; this reclassifies it from the code it was received
 * at to the code it was used on, as `reclass` pairs that sum to zero. Anything else charges every stocked delivery
 * twice.
 */
class MaterialIssue extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'construction_material_issues';

    protected $fillable = [
        'number', 'stock_location_id', 'issued_on',
        'issued_by_worker_id', 'issued_by_name', 'received_by_worker_id', 'received_by_name',
        'status', 'posted_at', 'posted_by', 'reversed_at', 'reversed_by', 'reversal_reason',
        'reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $issue): void {
            $issue->created_by ??= auth()->id();
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialIssueLine::class, 'material_issue_id');
    }

    /** The storeman who handed it over. */
    public function issuedByWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'issued_by_worker_id');
    }

    /** And whoever took it, which is the name somebody asks for when the material is not where it should be. */
    public function receivedByWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'received_by_worker_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /** What the docket was worth, from the lines' frozen figures rather than by revaluing consumed lots. */
    public function totalValue(): float
    {
        return round((float) $this->lines()->sum('amount'), 2);
    }

    /** Who to name on a screen: the worker record where there is one, the written name where there is not. */
    public function issuedBy(): ?string
    {
        return $this->issuedByWorker?->name ?? $this->issued_by_name;
    }

    public function receivedBy(): ?string
    {
        return $this->receivedByWorker?->name ?? $this->received_by_name;
    }

    public function displayName(): string
    {
        return $this->number;
    }
}
