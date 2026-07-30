<?php

namespace App\Models;

use App\Modules\Mpr\Models\MPR;
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
