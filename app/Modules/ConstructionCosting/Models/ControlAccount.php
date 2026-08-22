<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which general-ledger accounts §4 is about — `docs/construction-management-plan.md` §4.2.
 *
 * **A table rather than a config list**, because §4.2 wants a company to "name its own accounts and the report can name
 * them back". A hard-coded account code reconciles the wrong account in every tenant whose chart somebody else built,
 * and it does it silently: the difference simply comes out wrong and nothing says which account was read.
 *
 * Two columns and two different jobs:
 *
 *  - **`kind`** is §4.2's eight, and it is what the *report* reads. `cost` rows are the GL cost total; `wip`, `accrual`,
 *    `retention`, `contract_asset` and `contract_liability` are the positions the reconciling items move.
 *  - **`purpose`** is what the *posting service* reads, and it exists because two `recovery` accounts are
 *    indistinguishable by kind. It names the rule — `labour_burden` credits one, `plant_internal_hire` the other — and
 *    it is unique in the database rather than by convention, so a service can never find two candidates and take the
 *    first.
 */
class ControlAccount extends Model
{
    use Auditable;

    public const KIND_COST = 'cost';

    public const KIND_WIP = 'wip';

    public const KIND_ACCRUAL = 'accrual';

    public const KIND_RETENTION = 'retention';

    public const KIND_REVENUE = 'revenue';

    public const KIND_RECOVERY = 'recovery';

    public const KIND_CONTRACT_ASSET = 'contract_asset';

    public const KIND_CONTRACT_LIABILITY = 'contract_liability';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_COST => 'Job cost',
        self::KIND_WIP => 'Work in progress',
        self::KIND_ACCRUAL => 'Accruals',
        self::KIND_RETENTION => 'Retention',
        self::KIND_REVENUE => 'Contract revenue',
        self::KIND_RECOVERY => 'Recoveries and absorption',
        self::KIND_CONTRACT_ASSET => 'Contract asset',
        self::KIND_CONTRACT_LIABILITY => 'Contract liability',
    ];

    /**
     * **The posting rules, and every one of them is a credit somebody owes.**
     *
     * §7.3's warning is the reason this list is short and named rather than inferred: "charge either and never absorb
     * it and job cost exceeds GL cost by exactly the burden, growing every month, with no error anywhere". Each purpose
     * below is one of those credits, and the posting service refuses — by name — to post an entry whose rule has no
     * account against it.
     *
     * @var array<string, string>
     */
    public const PURPOSES = [
        // §7.3: labour burden charged at a rate has to credit somewhere, or the fleet of overheads looks free.
        'labour_burden' => 'Labour burden absorbed',
        // §7.3 again, and §7's note on which machines book cost: an owned machine has no invoice, so its log is the
        // cost and the credit is the recovery the depreciation and fuel accumulate against.
        'plant_internal_hire' => 'Plant internal hire recovery',
        // §4.1: site labour with no payroll behind it. Nobody else posted it, so construction owes both sides — and the
        // credit is a liability, because a gang paid next Friday is money the company owes today.
        'site_labour' => 'Site wages payable',
        // §4.5's first accrual: goods received not invoiced.
        'grni' => 'Goods received not invoiced',
        // §4.5's second: subcontract work done not certified.
        'subcontract_accrual' => 'Accrued subcontract costs',
        // §4.1: an overhead allocation is construction-only, so construction posts it.
        'overhead_allocation' => 'Overhead absorbed',
        // §4.4's movement posting.
        'wip_movement' => 'Work in progress movement',
    ];

    protected $table = 'construction_control_accounts';

    protected $fillable = [
        'account_id', 'kind', 'purpose', 'cost_type', 'label', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = ['is_active' => true];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * The account serving one posting rule, or null.
     *
     * Null rather than a fallback, deliberately. §4.3's fourth mechanism — "a forced close never fudges the ledger. No
     * plug entry, no balancing figure" — is the same principle one level down: a posting service that quietly credited a
     * suspense account when it could not find the right one would balance the journal and leave a figure in the accounts
     * nobody can explain, which §4.1 says "should be treated as a defect rather than a shortcut".
     */
    public static function forPurpose(string $purpose): ?self
    {
        return static::query()->active()->where('purpose', $purpose)->first();
    }

    /**
     * The cost account a cost type debits, falling back to a cost account that names no type.
     *
     * The fallback is the company that keeps one work-in-progress cost account for everything, which §4.2's table has
     * to allow — a required cost type would force it to invent a distinction it does not draw.
     */
    public static function costAccountFor(string $costType): ?self
    {
        return static::query()->active()->ofKind(self::KIND_COST)->where('cost_type', $costType)->first()
            ?? static::query()->active()->ofKind(self::KIND_COST)->whereNull('cost_type')->first();
    }

    /** @return array<int, int> */
    public static function accountIdsOfKind(string $kind): array
    {
        return static::query()->active()->ofKind($kind)->pluck('account_id')->all();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function displayName(): string
    {
        return $this->label ?: ($this->account?->code.' — '.$this->account?->name);
    }
}
