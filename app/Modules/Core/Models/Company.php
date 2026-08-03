<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;
use Spatie\Permission\PermissionRegistrar;

class Company extends SpatieTenant
{
    protected $table = 'companies';

    protected $fillable = [
        'name',
        'slug',
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
}
