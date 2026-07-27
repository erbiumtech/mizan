<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Top-level seeder for a multitenant install.
 *
 * Landlord data (permissions, users, the company registry) is seeded on the
 * default connection; every domain seeder below writes to a *tenant* database
 * and so only runs while a company is current. Without that split the tenant
 * connection still points at the non-existent `tenants/placeholder.sqlite`
 * from config/database.php.
 */
class DatabaseSeeder extends Seeder
{
    /** The seeded global super admin. */
    public const string SUPER_ADMIN_EMAIL = 'admin@erbium.tech';

    /**
     * Domain seeders that write to the current tenant's database.
     *
     * @var list<class-string<Seeder>>
     */
    protected array $tenantSeeders = [
        FiscalYearSeeder::class,
        SalarySlabSeeder::class,
        BankSeeder::class,
        EmployeeSeeder::class,
        EmployeeSettingSeeder::class,
        ChartOfAccountsSeeder::class,
        //            TransactionTypeSeeder::class,
        CompanyBankAccountSeeder::class,
        //            BeneficiarySeeder::class,
        AccountSeeder::class,
        //            JournalEntrySeeder::class,
        //            PayslipSeeder::class,
        //            FixedAssetSeeder::class,
        //            PettyCashSeeder::class,
        //            InventorySeeder::class,
        ContactSeeder::class,
        //            InvoiceSeeder::class,
    ];

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        // Landlord: permissions are global; users live alongside the registry.
        $this->call(PermissionSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'status' => 1,
            ]
        );

        // Global super admin: bypasses authorization everywhere (see the
        // Gate::before in AppServiceProvider) and can reach every company,
        // not just the ones it is a member of. Enforced on re-seed too, in
        // case the flag was toggled off in the UI.
        if (! $admin->is_super_admin) {
            $admin->forceFill(['is_super_admin' => true])->save();
        }

        Schema::enableForeignKeyConstraints();

        foreach ($this->companies($admin) as $company) {
            $this->seedTenant($company, $admin);
        }
    }

    /**
     * Every company to seed. CompanySeeder guarantees the default company
     * exists (provisioning its tenant database on a fresh install).
     *
     * @return iterable<Company>
     */
    protected function companies(User $admin): iterable
    {
        $companySeeder = new CompanySeeder;

        if ($this->command) {
            $companySeeder->setCommand($this->command);
        }

        $default = $companySeeder->seed($admin);

        return Company::query()->orderByRaw('id = ? desc', [$default->getKey()])->get();
    }

    protected function seedTenant(Company $company, User $admin): void
    {
        $this->command?->info("Seeding tenant: {$company->name}");

        $company->makeCurrent();

        $tenantConnection = config('multitenancy.tenant_database_connection_name');

        try {
            // Roles are spatie "teams" keyed by company, so they are per tenant.
            $this->call(RoleSeeder::class);

            Schema::connection($tenantConnection)->disableForeignKeyConstraints();

            $this->call($this->tenantSeeders);

            Schema::connection($tenantConnection)->enableForeignKeyConstraints();

            $this->attachAdmin($company, $admin);
        } finally {
            Company::forgetCurrent();
        }
    }

    /**
     * Ensure the seeded admin is a member of the company and holds the
     * Administrator role for this company's team.
     */
    protected function attachAdmin(Company $company, User $admin): void
    {
        if (! $company->users()->where('users.id', $admin->getKey())->exists()) {
            $company->users()->attach($admin->getKey());
        }

        $admin->assignRole('Administrator');
    }
}
