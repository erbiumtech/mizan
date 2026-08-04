<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Account extends Model
{
    use Auditable;

    /**
     * Counter-account for opening balances (see ChartOfAccountsSeeder).
     *
     * Every opening balance credits this account, so once each account's
     * opening figure has been entered it must net to zero. A non-zero balance
     * means the book was only half brought onto the system — the trial balance
     * surfaces it for exactly that reason.
     */
    public const OPENING_BALANCE_EQUITY_CODE = '3300';

    /**
     * Where a closed year's profit or loss lands (see FiscalYearClosingService).
     *
     * Income and expense accounts measure one period only, so closing a year
     * zeroes them and rolls the net into this account, which carries forward.
     */
    public const RETAINED_EARNINGS_CODE = '3200';

    protected $fillable = [
        'code', 'name', 'type', 'normal_balance', 'parent_id', 'currency_code',
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

        // Giving a posted-to account a child silently retires it: canAcceptEntries()
        // refuses parents, so every future posting to it fails while its existing
        // lines stay behind on what is now a group header. A misfiled 5802 under
        // 5100 Basic Salary Expense took out all payroll posting exactly this way,
        // and the error surfaced three layers down ("Line 0: account 5100 cannot
        // accept entries") with nothing pointing at the parent that caused it.
        static::saving(function (Account $account) {
            if (! $account->isDirty('parent_id') || ! $account->parent_id) {
                return;
            }

            $parent = static::find($account->parent_id);

            if ($parent && ! $parent->canHaveChildren()) {
                throw new InvalidArgumentException(
                    "Account {$parent->code} ({$parent->name}) already has journal entries, so it cannot "
                    .'become a parent account. Pick a group account as the parent, or move the existing '
                    ."entries off {$parent->code} first."
                );
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
     * Accounts that may be chosen as a parent: no journal lines of their own.
     */
    public function scopeGroupable($query)
    {
        return $query->whereDoesntHave('lines');
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
     * Why this account cannot receive lines, for an error a human can act on.
     */
    public function entryRefusalReason(): ?string
    {
        if (! $this->allow_manual_entry) {
            return 'it does not allow manual entry';
        }

        if ($this->children()->exists()) {
            return 'it has sub-accounts, which makes it a group header';
        }

        if (! $this->is_active) {
            return 'it is inactive';
        }

        return null;
    }

    /**
     * Whether this account may be given sub-accounts.
     *
     * False once it carries journal lines: see the saving guard in booted().
     */
    public function canHaveChildren(): bool
    {
        return ! $this->lines()->exists();
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
