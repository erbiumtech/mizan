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
 * data. Idempotent: an existing company with the same slug or name is left alone.
 *
 * The defaults are a dummy company. On an installation that already has a real
 * one, set SEED_COMPANY_NAME and SEED_COMPANY_SLUG in `.env` to its actual
 * values — otherwise re-seeding matches nothing and provisions a *second*,
 * dummy company with its own tenant database alongside the real one.
 */
class CompanySeeder extends Seeder
{
    public const string COMPANY_NAME = 'Demo Company (Private) Limited';

    /** Stable slug so re-seeding reuses the same tenant database file/schema. */
    public const string COMPANY_SLUG = 'demo-company';

    public function run(): void
    {
        $this->seed();
    }

    public static function companyName(): string
    {
        return (string) (env('SEED_COMPANY_NAME') ?: self::COMPANY_NAME);
    }

    public static function companySlug(): string
    {
        return (string) (env('SEED_COMPANY_SLUG') ?: self::COMPANY_SLUG);
    }

    /**
     * Seed the default company and return it, so callers (DatabaseSeeder) can
     * carry on seeding inside its tenant database.
     */
    public function seed(?User $creator = null): Company
    {
        $name = self::companyName();
        $slug = self::companySlug();

        $existing = Company::where('slug', $slug)
            ->orWhere('name', $name)
            ->first();

        if ($existing) {
            $this->command?->info("Company already exists: {$existing->name}");

            return $existing;
        }

        $creator ??= User::where('email', DatabaseSeeder::superAdminEmail())->first();

        $company = app(CompanyProvisioner::class)->provision(
            name: $name,
            slug: $slug,
            creator: $creator,
        );

        $this->command?->info("Provisioned company: {$company->name} ({$company->database})");

        return $company;
    }
}
