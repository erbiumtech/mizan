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
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /**
     * Determine whether the user can access the given Filament panel.
     * Only active accounts (status = 1) may sign in — mirrors the legacy
     * status-based login rule that previously lived in NovaAuthService.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (int) $this->status === 1;
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
        return $this->companies;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->companies()->whereKey($tenant->getKey())->exists();
    }

    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('User');
    }

    protected $fillable = [
        'name',
        'status',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
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
