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

    protected $fillable = [
        'name',
        'slug',
        'type',
        'profile',
        'database',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
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
