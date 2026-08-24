<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\CompanyProfiles;
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
    /**
     * The seeded global super admin — a dummy address by default.
     *
     * On an installation that already has a real super admin, set
     * SEED_ADMIN_EMAIL in `.env` to its address. Otherwise seeding creates a
     * *second* super-admin account (with the well-known password below) instead
     * of matching the existing one.
     */
    public const string SUPER_ADMIN_EMAIL = 'admin@example.test';

    /** Password given to the seeded admin; dummy data, so intentionally weak. */
    public const string SUPER_ADMIN_PASSWORD = 'password';

    public static function superAdminEmail(): string
    {
        return (string) (config('seeding.admin_email') ?: self::SUPER_ADMIN_EMAIL);
    }

    /**
     * Dummy data seeded on top of a company's baseline, for a development
     * install that wants something on the screens.
     *
     * Business tenants only, and each entry says why: pay components describe
     * payroll, the demo bank accounts are earmarked by business transaction type
     * (salary, rent), and the tax rates post to 2150 Sales Tax Payable. A
     * personal account has no payroll, no such transaction types and no such
     * account, so it gets its baseline and nothing else.
     *
     * @var list<class-string<Seeder>>
     */
    protected array $businessDemoSeeders = [
        // EmployeeSeeder::class,
        // EmployeeSettingSeeder::class,
        PayComponentSeeder::class,
        CompanyBankAccountSeeder::class,
        // BeneficiarySeeder::class,
        // JournalEntrySeeder::class,
        // PayslipSeeder::class,
        // FixedAssetSeeder::class,
        // PettyCashSeeder::class,
        // InventorySeeder::class,
        // ContactSeeder::class,
        TaxRateSeeder::class,
        // InvoiceSeeder::class,
    ];

    /**
     * What to seed into one company's database.
     *
     * The baseline comes from the company's own profile, exactly as
     * CompanyProvisioner and tenants:seed-baseline already take it. This used to
     * be one hardcoded list applied to every company, which meant `db:seed` ran
     * the *business* chart of accounts over a *personal* account — renaming its
     * 4000 Salary to "Income", 1000 Cash in Hand to "Assets" and so on, and then
     * dying on the guard in Account::booted() when it tried to hang 4100 under a
     * 4000 that already had a salary posted to it.
     *
     * Public so TenantBaselineCompletenessTest can pin the routing without
     * provisioning a database: it reads the company's profile and type and
     * touches nothing else.
     *
     * @return list<class-string<Seeder>>
     */
    public function seedersFor(Company $company): array
    {
        $baseline = CompanyProfiles::seeders($company->profile, $company->type);

        if ($company->isPersonal()) {
            return array_values($baseline);
        }

        return array_values(array_unique([...$baseline, ...$this->businessDemoSeeders]));
    }

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        // Landlord: permissions are global; users live alongside the registry.
        $this->call(PermissionSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => self::superAdminEmail()],
            [
                'name' => 'Administrator',
                'password' => Hash::make(self::SUPER_ADMIN_PASSWORD),
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

            $this->call($this->seedersFor($company));

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
