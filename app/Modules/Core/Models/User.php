<?php

namespace App\Modules\Core\Models;

use App\Modules\Mpr\Models\MPR;
use App\Traits\Auditable;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\PermissionRegistrar;
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

    /**
     * Take this user off one company: they can no longer sign in to it and hold
     * nothing there.
     *
     * What a company's Users page means by "delete". The account itself is a
     * landlord row shared by every company the person works for, so deleting it
     * from one company's panel would take them out of all the others — and take
     * the row that every payslip, MPR and audit entry of theirs points at. What
     * the page can end is this company's claim on them: the `company_user` pivot,
     * which is what grants access, and their roles here, which are per-company
     * (spatie teams) and would otherwise come back with them on a re-add.
     *
     * Their employee record stays. It is this company's own history — payslips,
     * settings, the lot — and losing it is not what removing a member means.
     */
    public function removeFromCompany(Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($company->getKey());
            $this->fresh()?->syncRoles([]);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }

        $this->companies()->detach($company->getKey());
    }

    /**
     * Only the users who may access the company being served.
     *
     * The membership boundary that has to be drawn by hand for this table: it is
     * in the landlord database, shared by every company, so unlike an Employee or
     * a Payslip a User is not isolated by the connection it is read over. Applied
     * for real by UserResource; also available to the queries that go around a
     * resource — a table filter's option list, a select — since those would
     * otherwise name every account in the system.
     *
     * No current company (login, console, a landlord-level job) means no
     * narrowing: there is no company whose members to narrow to.
     *
     * @param  Builder<User>  $query
     */
    public function scopeInCurrentCompany(Builder $query): void
    {
        $company = Filament::getTenant() ?? Company::current();

        if (! $company instanceof Company) {
            return;
        }

        // Both sides are landlord tables, so this subquery does not span the
        // landlord/tenant connection split (see App\Support\LandlordUserColumn
        // for the queries that would).
        $query->whereHas('companies', fn (Builder $companies) => $companies->whereKey($company->getKey()));
    }

    /**
     * Drop the platform's own accounts, unless a platform account is asking.
     *
     * A super admin is attached to the companies they create — the provisioner
     * does it, and they hold the Administrator role there — but they are not one
     * of the company's people, and a company has no business administering them.
     * Listed, they came with a Deactivate button and an Edit form: a company
     * administrator could lock the account that runs the installation out of
     * every company in it.
     *
     * @param  Builder<User>  $query
     */
    public function scopeExceptPlatformAdmins(Builder $query): void
    {
        if (auth()->user()?->isSuperAdmin()) {
            return;
        }

        $query->where($query->getModel()->qualifyColumn('is_super_admin'), false);
    }

    /**
     * Every user, current company or not.
     *
     * `users` is a landlord table shared by all companies, so UserResource turns
     * Filament's row scoping back on and, while the panel is serving a request,
     * that lands as a global scope on this model. The Companies resource — super
     * admin only — is the one place that has to reach past it: the whole point of
     * assigning a company's Administrator is picking someone who is not a member
     * yet. Anywhere else, wanting this is a bug.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAcrossCompanies(Builder $query): void
    {
        // No current panel means no scope was ever applied — Filament registers
        // it against one panel and the closure no-ops outside it.
        $panel = Filament::getCurrentPanel();

        if ($panel?->hasTenancy()) {
            $query->withoutGlobalScope($panel->getTenancyScopeName());
        }
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
     * Whether this user may create new companies. Super admins only.
     *
     * Registering a company provisions a database, migrates it and seeds its
     * roles — an installation-level act, not something a company administrator
     * does within their own. It used to be enough to hold Administrator in any
     * company you belonged to, which let any customer's admin add companies to
     * the installation. The rest of company management (view, update, delete —
     * see CompanyPolicy) was already super-admin only; this is the last piece
     * lining up with it.
     */
    public function canCreateCompanies(): bool
    {
        return $this->isSuperAdmin();
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
