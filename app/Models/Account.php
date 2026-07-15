<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use Auditable;

    protected $fillable = [
        'code', 'name', 'type', 'normal_balance', 'parent_id',
        'is_active', 'allow_manual_entry', 'description', 'balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_manual_entry' => 'boolean',
        'balance' => 'decimal:2',
    ];

    protected $attributes = [
        'is_active' => true,
        'allow_manual_entry' => true,
        'balance' => 0,
    ];

    protected static function booted()
    {
        static::creating(function (Account $account) {
            if (! $account->normal_balance) {
                $account->normal_balance = in_array($account->type, ['asset', 'expense'])
                    ? 'debit'
                    : 'credit';
            }
        });
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * Only active leaf accounts that allow manual entry may receive lines.
     */
    public function canAcceptEntries(): bool
    {
        if (! $this->allow_manual_entry) {
            return false;
        }

        if ($this->children()->exists()) {
            return false;
        }

        return $this->is_active;
    }

    /**
     * Own balance plus all descendants', for hierarchy roll-ups.
     */
    public function getCalculatedBalanceAttribute(): float
    {
        $balance = (float) $this->balance;

        foreach ($this->children as $child) {
            $balance += $child->calculated_balance;
        }

        return $balance;
    }
}
