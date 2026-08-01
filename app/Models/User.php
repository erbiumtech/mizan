<?php

namespace App\Models;

use App\Traits\Auditable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /**
     * Users live in the landlord database. Pin the connection so that when a
     * tenant model (e.g. Employee) eager-loads its `user` relation, the related
     * query does NOT inherit the tenant connection (Eloquent's default) and
     * fail with "no such table: users".
     */
    public function getConnectionName(): ?string
    {
        return config('multitenancy.landlord_database_connection_name') ?: config('database.default');
    }

    /**
     * Determine whether the user can access the given Filament panel.
     * Only active accounts (status = 1) may sign in — mirrors the legacy
     * status-based login rule that previously lived in NovaAuthService.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (int) $this->status === 1;
    }

    /** Global super admin — manages companies across all tenants. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Does this user hold administrator authority in the company being served?
     *
     * The same rule Gate::before applies — a super admin may do anything, an
     * Administrator may do anything but create a company — except that this can
     * be asked outside the Gate, which is where it was being got wrong. A
     * canAccess() on a page, an action's visible(): none of them go through the
     * Gate, so `hasRole('Administrator')` on its own quietly excluded super
     * admins. They are not Administrators of most companies — they switch into
     * any company without a membership row, let alone a role in its team — so
     * Company Settings simply vanished from the sidebar of every company they had
     * not been given a role in.
     */
    public function isAdministrator(): bool
    {
        return $this->isSuperAdmin() || $this->hasRole('Administrator');
    }

    /**
     * Companies (tenants) this user may access.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withTimestamps();
    }

    public function getTenants(Panel $panel): Collection
    {
        // Super admins may switch into any company.
        return $this->isSuperAdmin() ? Company::all() : $this->companies;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->isSuperAdmin()
            || $this->companies()->whereKey($tenant->getKey())->exists();
    }

    /**
     * Whether this user may create new companies — i.e. is an Administrator in
     * at least one of the companies they belong to. Roles are per-company
     * (spatie teams), so we check each company's team context.
     */
    public function canCreateCompanies(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            foreach ($this->companies as $company) {
                $registrar->setPermissionsTeamId($company->getKey());

                if ($this->fresh()->hasRole('Administrator')) {
                    return true;
                }
            }

            return false;
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('User');
    }

    protected $fillable = [
        'name',
        'status',
        'email',
        'password',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    // protected static function booted()
    // {
    //     static::creating(function ($user) {
    //         if (empty($user->password)) {
    //             $user->password = Hash::make('password');
    //         }
    //     });
    // }

    public function mprs()
    {
        return $this->hasMany(MPR::class, 'user_id');
    }
}
