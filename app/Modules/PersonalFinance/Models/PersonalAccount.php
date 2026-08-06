<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use App\Modules\PersonalFinance\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a person's own chart of accounts.
 *
 * The same five types as the company chart, and for the same reason: they are
 * what a balance sheet and an income statement are built out of. Asset and
 * expense accounts are debit-normal, the rest credit-normal.
 *
 * Unlike App\Modules\Accounting\Models\Account there is no cached `balance`
 * column. That column is a single scalar maintained on posting, which cannot
 * represent a balance per owner — and the company's own report services already
 * bypass it and sum the lines instead, so nothing is lost by not having it.
 */
class PersonalAccount extends Model
{
    use BelongsToOwner;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    /** Types whose balance grows on the debit side. */
    public const DEBIT_NORMAL = [self::TYPE_ASSET, self::TYPE_EXPENSE];

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'type',
        'tax_regime',
        'opening_balance',
        'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PersonalEntryLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    /**
     * What this account is worth now: its opening balance plus every line posted
     * against it, signed so that a debit-normal account grows on debits.
     *
     * Summed on demand rather than cached — see the class comment.
     */
    public function balance(): float
    {
        $debits = (float) $this->lines()->sum('debit');
        $credits = (float) $this->lines()->sum('credit');

        $movement = $this->isDebitNormal()
            ? $debits - $credits
            : $credits - $debits;

        return round((float) $this->opening_balance + $movement, 2);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
