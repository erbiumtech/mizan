<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery, as site recorded it — `docs/construction-management-plan.md` §16.1.
 *
 * **This is the docket, not the valuation.** §16.1 lists what a delivery line carries — docket number, received-by,
 * order reference, contract item, and the materials-on-site flag — and there is no rate and no amount in that list.
 * §5's goods receipt is where a delivery becomes money; this is where it becomes a fact somebody signed for. Keeping
 * them apart is what lets a contractor run a site diary without buying a cost ledger, and it is why nothing here
 * multiplies a quantity by anything.
 *
 * Two questions this table answers that no other table can:
 *
 *  - **What is standing on site**, where there is no stock ledger to ask. `is_materials_on_site` is corroboration for
 *    Phase 8c's figure when the cost module is there, and the only record of it when the cost module is not.
 *  - **Which dockets accounts have never seen.** A delivery with no `goods_receipt_id` is material the job has received
 *    and the cost ledger has not heard about: cost understated, margin overstated, and no error anywhere. The same
 *    silence §13's notice clock exists for, in the procurement chain instead of the contractual one.
 */
class DailyLogDelivery extends Model
{
    public const CONDITION_ACCEPTED = 'accepted';

    public const CONDITION_ACCEPTED_WITH_DAMAGE = 'accepted_with_damage';

    public const CONDITION_REJECTED = 'rejected';

    /** @var array<string, string> */
    public const CONDITIONS = [
        self::CONDITION_ACCEPTED => 'Accepted',
        self::CONDITION_ACCEPTED_WITH_DAMAGE => 'Accepted with damage',
        self::CONDITION_REJECTED => 'Rejected',
    ];

    protected $table = 'construction_daily_log_deliveries';

    protected $fillable = [
        'daily_log_id', 'docket_number', 'supplier_contact_id', 'supplier_label', 'order_reference',
        'contract_item_id', 'description', 'quantity', 'unit_of_measure',
        'received_by', 'received_at', 'condition', 'condition_notes',
        'is_materials_on_site', 'goods_receipt_id', 'notes',
    ];

    protected $casts = [
        'is_materials_on_site' => 'boolean',
    ];

    protected $attributes = [
        'condition' => self::CONDITION_ACCEPTED,
        'is_materials_on_site' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            $delivery->received_by ??= auth()->id();
        });
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class, 'daily_log_id');
    }

    /**
     * Who delivered it, where the company has a contact record.
     *
     * A guarded coupling, not a requirement: a supplier is a Contact and Invoicing owns those, while this module
     * requires only `construction`. Without it the column is null and `supplier_label` — the name written on the
     * docket — carries the answer. A delivery must never be unrecordable because of a licence.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_contact_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DailyLogPhoto::class, 'delivery_id');
    }

    /** Flagged as standing on site and unconsumed — §16.1's `is_materials_on_site`. */
    public function scopeOnSite(Builder $query): Builder
    {
        return $query->where('is_materials_on_site', true);
    }

    /**
     * Dockets with no priced receipt behind them.
     *
     * The exposure query. Only meaningful where `construction_costing` is licensed — without it every delivery is
     * unreceipted and the list is the whole register — which is why the service holds that guard rather than this scope.
     */
    public function scopeUnreceipted(Builder $query): Builder
    {
        return $query->whereNull('goods_receipt_id');
    }

    public function isReceipted(): bool
    {
        return $this->goods_receipt_id !== null;
    }

    /** No docket, said out loud. The delivery is still recorded; the gap is what the table shows. */
    public function hasNoDocket(): bool
    {
        return blank($this->docket_number);
    }

    public function wasRejected(): bool
    {
        return $this->condition === self::CONDITION_REJECTED;
    }

    /**
     * Taken despite damage — the fact that disappears without the middle condition.
     *
     * The load was accepted because the pour was booked, and this is the record that somebody said so on the day.
     */
    public function wasAcceptedWithDamage(): bool
    {
        return $this->condition === self::CONDITION_ACCEPTED_WITH_DAMAGE;
    }

    public function supplierName(): string
    {
        return $this->supplier_label
            ?? (modules()->enabled('invoicing') ? $this->supplier?->name : null)
            ?? 'Supplier not named';
    }

    public function displayName(): string
    {
        return ($this->docket_number ?? 'No docket').' — '.$this->description;
    }
}
