<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Support\Collection;

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

    /**
     * Children with their own children eager-loaded all the way down
     * (for the chart-of-accounts tree endpoint).
     */
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive')->orderBy('code');
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * All journal entries that touch this account (through its lines).
     */
    public function journalEntries()
    {
        return $this->hasManyThrough(
            JournalEntry::class,
            JournalEntryLine::class,
            'account_id',
            'id',
            'id',
            'journal_entry_id'
        )->distinct();
    }

    /**
     * All descendant accounts, depth-first.
     */
    public function descendants(): Collection
    {
        return $this->children->flatMap(
            fn (Account $child) => collect([$child])->merge($child->descendants())
        );
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopePostable($query)
    {
        return $query->where('is_active', true)
            ->where('allow_manual_entry', true)
            ->whereDoesntHave('children');
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
