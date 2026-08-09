<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Assign a role to a user within a specific company (spatie teams). Ensures the
 * user is a member of the company, sets the team context, and assigns the role.
 */
class AssignUserRole extends Command
{
    protected $signature = 'user:assign-role
        {email : The user email}
        {role : Role name (e.g. Administrator, Accountant)}
        {--company= : Company slug or id (required — roles are per-company)}';

    protected $description = 'Assign a role to a user for a specific company';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $companyRef = $this->option('company');
        if (! $companyRef) {
            $this->error('The --company option is required (roles are per-company).');

            return self::FAILURE;
        }

        $company = Company::where('slug', $companyRef)->orWhere('id', $companyRef)->first();
        if (! $company) {
            $this->error("No company matching '{$companyRef}'.");

            return self::FAILURE;
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            if (! Role::where('name', $this->argument('role'))->where('company_id', $company->getKey())->exists()) {
                $this->error("Role '{$this->argument('role')}' does not exist for company '{$company->slug}'. Seed it first: php artisan tenants:artisan \"db:seed --class=RoleSeeder --force\"");

                return self::FAILURE;
            }

            if (! $company->users()->whereKey($user->getKey())->exists()) {
                $company->users()->attach($user->getKey());
                $this->info("Attached {$user->email} to {$company->slug}.");
            }

            $user->assignRole($this->argument('role'));
        } finally {
            $registrar->setPermissionsTeamId(null);
        }

        $this->info("Assigned '{$this->argument('role')}' to {$user->email} in company '{$company->slug}'.");

        return self::SUCCESS;
    }
}
