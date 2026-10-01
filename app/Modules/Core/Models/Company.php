<?php

namespace App\Modules\Core\Models;

use App\Support\CompanyProfiles;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;
use Spatie\Permission\PermissionRegistrar;

class Company extends SpatieTenant
{
    protected $table = 'companies';

    /**
     * A tenant is either a business or one person's own affairs.
     *
     * The distinction is presentational and configurational, not structural: a
     * personal account gets its own database, roles and staff exactly like a
     * business, because a household with an accountant and a cook is a very
     * small organisation and the machinery is identical.
     */
    public const TYPE_BUSINESS = 'business';

    public const TYPE_PERSONAL = 'personal';

    /**
     * The legal/tax entity, separate from `type` (the chart switch) and `profile`
     * (the module preset) — it decides which tax engine the return pack uses.
     * See docs/legal-entity-types-plan.md. Null means "derive from type", so an
     * existing company that never sets it behaves exactly as before: a business
     * is a `company`, a personal account an `individual`.
     *
     * Only the values with distinct tax behaviour are defined as the phases that
     * need them land. `small_company` is the first beyond the two derived
     * defaults: a company taxed at the s.2(59A) reduced rate.
     */
    public const LEGAL_INDIVIDUAL = 'individual';

    public const LEGAL_SOLE_PROPRIETOR = 'sole_proprietor';

    public const LEGAL_AOP = 'aop';

    public const LEGAL_COMPANY = 'company';

    public const LEGAL_SMALL_COMPANY = 'small_company';

    /**
     * A Limited Liability Partnership (LLP Act 2017). A body corporate, so the
     * Income Tax Ordinance's definition of "company" (s.80) catches it: taxed as
     * a company, at the corporate rate, NOT on the AOP slabs — it opens the
     * Corporate pack. Its own value rather than folded into `company` so an
     * operator can record what the entity actually is. See §7 Q7 of the plan;
     * researched, advisor to confirm.
     */
    public const LEGAL_LLP = 'llp';

    /**
     * An approved non-profit (s.2(36)). The exemption is a 100% tax credit under
     * s.100C with conditions, plus a s.113 minimum-tax carve-out — not a blanket
     * exemption, and the return is still filed. Only the scaffolding is built:
     * the approval fields below and the Corporate pack's notice. The credit math
     * is §7 Q3, held for the advisor (the one with real legal exposure).
     */
    public const LEGAL_NON_PROFIT = 'non_profit';

    /** @var array<string, string> the legal entities an operator may pick, label by value */
    public const LEGAL_ENTITY_LABELS = [
        self::LEGAL_INDIVIDUAL => 'Individual',
        self::LEGAL_SOLE_PROPRIETOR => 'Sole proprietor (business taxed on individual slabs)',
        self::LEGAL_AOP => 'Partnership / AOP (non-salaried slab schedule)',
        self::LEGAL_COMPANY => 'Company',
        self::LEGAL_SMALL_COMPANY => 'Small company (s.2(59A) reduced rate)',
        self::LEGAL_LLP => 'Limited Liability Partnership (taxed as a company)',
        self::LEGAL_NON_PROFIT => 'Non-profit / NGO (approved u/s 2(36))',
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'profile',
        'legal_entity',
        'tax_exempt_ref',
        'tax_exempt_approved_on',
        'database',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
        'tax_exempt_approved_on' => 'date',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Make this the company the request reads from.
     *
     * Not simply makeCurrent(). Where a dedicated tenant connection is configured
     * — production — that is exactly right: it switches the database, the cache
     * prefix and the filesystem. The test suite has no such connection, it runs
     * everything on one database, and makeCurrent() throws outright there. What
     * still has to happen in both is the permission team id, since roles are
     * per-company.
     *
     * One definition because there are two callers who must not disagree: the
     * panel, through SyncSpatieTenant when Filament sets its tenant, and the pages
     * outside the panel, through ResolveCompanyFromRoute.
     */
    public function activate(): void
    {
        if (config('multitenancy.tenant_database_connection_name')) {
            $this->makeCurrent();

            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->getKey());
    }

    /**
     * Users who may access this company (Filament tenant membership).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withTimestamps();
    }

    /**
     * This company's roles.
     *
     * Roles are per-company (spatie teams), so every row carries a `company_id` and one
     * company's Accountant is not another's. Named as a relation because that is the only
     * honest way to show them: listed flat, five names across N companies read as
     * duplicates of each other.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(
            config('permission.models.role'),
            config('permission.column_names.team_foreign_key', 'company_id'),
        );
    }

    /**
     * What this company has been sold, and what it currently has switched on.
     *
     * Landlord rows, so they are readable with no tenant current — which is what lets a
     * platform admin grant a licence without entering the company.
     */
    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    /** One person's own affairs rather than a business. */
    public function isPersonal(): bool
    {
        return $this->type === self::TYPE_PERSONAL;
    }

    /**
     * The legal/tax entity, falling back to the one `type` implies when unset —
     * so a stored value overrides, and its absence reproduces today's behaviour.
     */
    public function legalEntity(): string
    {
        return $this->legal_entity ?: ($this->isPersonal() ? self::LEGAL_INDIVIDUAL : self::LEGAL_COMPANY);
    }

    /** A company taxed at the s.2(59A) small-company reduced rate. */
    public function isSmallCompany(): bool
    {
        return $this->legalEntity() === self::LEGAL_SMALL_COMPANY;
    }

    /**
     * A Limited Liability Partnership — a body corporate taxed as a company, so
     * it opens the Corporate pack, not the AOP/slab one. See the constant.
     */
    public function isLlp(): bool
    {
        return $this->legalEntity() === self::LEGAL_LLP;
    }

    /** An approved non-profit (s.2(36)). See the constant for what is and is not built. */
    public function isNonProfit(): bool
    {
        return $this->legalEntity() === self::LEGAL_NON_PROFIT;
    }

    /**
     * A sole proprietor: a business (business modules, business chart) whose
     * profit is taxed on the owner's individual slabs rather than a company rate.
     * The return pack it opens is the sole-proprietor pack, not the corporate one.
     */
    public function isSoleProprietor(): bool
    {
        return $this->legalEntity() === self::LEGAL_SOLE_PROPRIETOR;
    }

    /**
     * An association of persons / partnership: a business whose profit is taxed
     * on the non-salaried/AOP slab schedule — the same schedule as a sole
     * proprietor's business income, which is why both open the same pack.
     */
    public function isAop(): bool
    {
        return $this->legalEntity() === self::LEGAL_AOP;
    }

    /**
     * A business whose profit files on the individual/AOP slab schedule rather
     * than a company flat rate — a sole proprietor or an AOP. Both open the
     * slab-business return pack; an ordinary company or small company opens the
     * corporate one. The single predicate both packs gate on, so a new slab
     * entity is added in one place, not scattered across every canAccess().
     */
    public function isSlabTaxedBusiness(): bool
    {
        return $this->isSoleProprietor() || $this->isAop();
    }

    /** Short name for the slab-business return pack this entity opens. */
    public function slabBusinessLabel(): string
    {
        return $this->isAop() ? 'Partnership / AOP' : 'Sole Proprietor';
    }

    /**
     * What to call each kind on screen.
     *
     * "Company" is wrong for somebody's household, and being addressed as a
     * company while recording your grocery bill is the kind of small wrongness
     * that makes software feel like it was not meant for you.
     *
     * A constant rather than a match inside typeLabel(), because the create
     * form and the companies list both need the pair as options — and when they
     * each wrote their own, the form offered "Business" and "Personal account"
     * while every other screen said "Company" and "Personal Account".
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_BUSINESS => 'Company',
        self::TYPE_PERSONAL => 'Personal Account',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? self::TYPE_LABELS[self::TYPE_BUSINESS];
    }

    /**
     * What shape of business this is — the companion to type, and the thing that
     * decided its starting modules and baseline.
     *
     * Null for every company created before profiles existed, which is honest:
     * they were licensed from the registry defaults by hand. CompanyProfiles
     * reads null as "no preset", never as an error.
     */
    public function profileLabel(): string
    {
        return CompanyProfiles::label($this->profile);
    }

    public function hasProfile(): bool
    {
        return CompanyProfiles::has($this->profile);
    }

    /*
     * There were scopePersonal() and scopeBusiness() here. Both were dead from
     * the commit that added them and are deleted rather than kept for a caller
     * that never arrived — `isPersonal()` answers this about a record, and a
     * query wanting it is one where() away.
     *
     * scopeBusiness() was worth removing on its own account. It read as "not a
     * personal account" and meant "type is exactly business", which are the same
     * set only while there are exactly two types. Nothing enforced that, so the
     * scope was a correct-looking query waiting for a third type to make it
     * silently wrong — it would have dropped the new kind from both scopes at
     * once. Company profiles exist so that the *shape* of a business is not more
     * values of `type`, which keeps the pair honest; this removes the trap that
     * would have been sprung if anyone decided otherwise.
     */
}
