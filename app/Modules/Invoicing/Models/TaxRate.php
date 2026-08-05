<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A rate of tax, and where it is booked.
 *
 * The rate is a percentage — 18.0000 is 18% — because that is how it is legislated,
 * quoted and printed. Turning it into a fraction happens at the one place that
 * multiplies by it.
 */
class TaxRate extends Model
{
    use Auditable;

    /** Where tax posts when a rate names no account of its own. */
    public const DEFAULT_ACCOUNT_CODE = '2150';

    protected $fillable = ['name', 'code', 'rate', 'account_id', 'is_active', 'is_default'];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
    ];

    protected static function booted(): void
    {
        /*
         * A rate that has been charged cannot be deleted, enforced here rather than
         * only in the policy.
         *
         * Administrators and super admins pass every policy check — see the
         * Gate::before in AppServiceProvider — so a policy method cannot express a
         * restriction that has to hold for everyone. The rate is the record of why
         * an issued invoice charged what it did; deactivating is what stops it being
         * offered.
         */
        static::deleting(function (self $rate): void {
            if ($rate->lines()->exists()) {
                throw new InvalidArgumentException(
                    "\"{$rate->name}\" has been charged on ".$rate->lines()->count()
                    .' invoice line(s), so it is part of what those invoices say. Switch it off instead.'
                );
            }
        });

        // One default at a time, asserted the same way the fiscal year does it: a
        // model stood down since being loaded is not dirty when set again, so the
        // row is written explicitly rather than assumed.
        static::saved(function (self $rate): void {
            if (! $rate->is_default) {
                return;
            }

            static::whereKey($rate->getKey())->update(['is_default' => true]);
            static::whereKeyNot($rate->getKey())->where('is_default', true)->update(['is_default' => false]);
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The rate as a multiplier: 18% becomes 0.18. */
    public function fraction(): float
    {
        return round((float) $this->rate / 100, 6);
    }

    /**
     * The tax in an amount that already includes it.
     *
     * 118 at 18% is 18 of tax, not 21.24 — the difference between this and
     * taxOn() is the whole of the inclusive/exclusive question, and getting it
     * wrong overstates the tax and understates the revenue.
     */
    public function taxWithin(float $grossAmount): float
    {
        $fraction = $this->fraction();

        return $fraction === 0.0 ? 0.0 : round($grossAmount * $fraction / (1 + $fraction), 2);
    }

    /** The tax added to an amount that does not include it. */
    public function taxOn(float $netAmount): float
    {
        return round($netAmount * $this->fraction(), 2);
    }

    public function accountId(): ?int
    {
        return $this->account_id
            ?? Account::where('code', self::DEFAULT_ACCOUNT_CODE)->value('id');
    }

    public function label(): string
    {
        return $this->name.' ('.rtrim(rtrim(number_format((float) $this->rate, 4, '.', ''), '0'), '.').'%)';
    }
}
