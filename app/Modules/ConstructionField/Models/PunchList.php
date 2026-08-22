<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One punch or snag list — `docs/construction-management-plan.md` §16.4.
 *
 * A list is the *occasion*: a pre-handover sweep of level four, the employer's walk-round at Taking-Over, a defects
 * list raised eight months into the liability period. The items are what carry money.
 *
 * **`internal` against `client` is the distinction on this table that matters.** An internal list is the contractor's
 * own quality sweep, made before anybody else is invited to look. A client list is the employer's. Merging them would
 * put the contractor's own findings into a document the employer can quote, which is the fastest way to teach a site
 * team to stop writing anything down.
 *
 * **A list closes only when its items do**, and that is enforced rather than trusted: a closed list with open items on
 * it is a handover certificate nobody should have signed.
 */
class PunchList extends Model
{
    use Auditable;

    public const KIND_PRE_HANDOVER = 'pre_handover';

    public const KIND_HANDOVER = 'handover';

    public const KIND_DEFECTS = 'defects';

    public const KIND_CLIENT = 'client';

    public const KIND_INTERNAL = 'internal';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_PRE_HANDOVER => 'Pre-handover',
        self::KIND_HANDOVER => 'Handover',
        self::KIND_DEFECTS => 'Defects liability',
        self::KIND_CLIENT => 'Client list',
        self::KIND_INTERNAL => 'Internal quality sweep',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'construction_punch_lists';

    protected $fillable = [
        'job_id', 'contract_id', 'name', 'kind', 'location_id',
        'opened_on', 'target_completion_date', 'closed_on', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'opened_on' => 'date',
        'target_completion_date' => 'date',
        'closed_on' => 'date',
    ];

    protected $attributes = [
        'kind' => self::KIND_PRE_HANDOVER,
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $list): void {
            $list->created_by ??= auth()->id();
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

    public function items(): HasMany
    {
        return $this->hasMany(PunchItem::class, 'punch_list_id')->orderBy('reference');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * The employer's lists, which are the ones that can be quoted back.
     *
     * The contractor's own sweep is deliberately not in here — see the class docblock.
     */
    public function scopeExternal(Builder $query): Builder
    {
        return $query->whereNot('kind', self::KIND_INTERNAL);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** The contractor's own, not to be handed over. */
    public function isInternal(): bool
    {
        return $this->kind === self::KIND_INTERNAL;
    }

    public function openItems(): int
    {
        return $this->items->filter(fn (PunchItem $item): bool => ! $item->isClosed())->count();
    }

    /** Open items that stop the employer taking the building over — what §11's holdback is built from. */
    public function blockingItems(): int
    {
        return $this->items
            ->filter(fn (PunchItem $item): bool => ! $item->isClosed() && $item->affects_practical_completion)
            ->count();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function displayName(): string
    {
        return $this->name;
    }
}
