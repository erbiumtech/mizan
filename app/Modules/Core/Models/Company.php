<?php

namespace App\Modules\Core\Models;

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

    public function scopePersonal($query)
    {
        return $query->where('type', self::TYPE_PERSONAL);
    }

    public function scopeBusiness($query)
    {
        return $query->where('type', self::TYPE_BUSINESS);
    }
}
