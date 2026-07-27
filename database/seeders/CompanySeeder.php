<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Multitenancy\CompanyProvisioner;
use Illuminate\Database\Seeder;

/**
 * Creates the default company in the landlord registry.
 *
 * Provisioning goes through CompanyProvisioner, so this also creates and
 * migrates the company's own tenant database and seeds its baseline reference
 * data. Idempotent: an existing company with the same name is left alone.
 */
class CompanySeeder extends Seeder
{
    public const string COMPANY_NAME = 'ERBIUMTECH (SMC-PRIVATE) LIMITED';

    /** Stable slug so re-seeding reuses the same tenant database file/schema. */
    public const string COMPANY_SLUG = 'erbiumtech-smc-private-limited';

    public function run(): void
    {
        $this->seed();
    }

    /**
     * Seed the default company and return it, so callers (DatabaseSeeder) can
     * carry on seeding inside its tenant database.
     */
    public function seed(?User $creator = null): Company
    {
        $existing = Company::where('slug', self::COMPANY_SLUG)
            ->orWhere('name', self::COMPANY_NAME)
            ->first();

        if ($existing) {
            $this->command?->info("Company already exists: {$existing->name}");

            return $existing;
        }

        $creator ??= User::where('email', DatabaseSeeder::SUPER_ADMIN_EMAIL)->first();

        $company = app(CompanyProvisioner::class)->provision(
            name: self::COMPANY_NAME,
            slug: self::COMPANY_SLUG,
            creator: $creator,
        );

        $this->command?->info("Provisioned company: {$company->name} ({$company->database})");

        return $company;
    }
}
