<?php

namespace Database\Seeders\Production;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;

/**
 * REAL PRODUCTION DATA — the actual company, kept out of the default seed run.
 *
 * CompanySeeder now defaults to a dummy company. Rather than duplicating the
 * provisioning logic, this records the real identifiers and hands them to the
 * same seeder, so there is one code path.
 *
 * Prefer setting these in `.env` on the production host instead of running this,
 * because CompanySeeder reads them and then *matches* the existing company
 * rather than provisioning anything:
 *
 *     SEED_COMPANY_NAME="ERBIUMTECH (SMC-PRIVATE) LIMITED"
 *     SEED_COMPANY_SLUG=erbiumtech-smc-private-limited
 *     SEED_ADMIN_EMAIL=admin@erbium.tech
 */
class RealCompanySeeder extends Seeder
{
    public const string COMPANY_NAME = 'ERBIUMTECH (SMC-PRIVATE) LIMITED';

    public const string COMPANY_SLUG = 'erbiumtech-smc-private-limited';

    public const string SUPER_ADMIN_EMAIL = 'admin@erbium.tech';

    public function run(): void
    {
        // CompanySeeder reads these from config, so set them for this process.
        config([
            'seeding.company_name' => self::COMPANY_NAME,
            'seeding.company_slug' => self::COMPANY_SLUG,
            'seeding.admin_email' => self::SUPER_ADMIN_EMAIL,
        ]);

        $creator = User::where('email', DatabaseSeeder::superAdminEmail())->first();

        $seeder = new CompanySeeder;

        if ($this->command) {
            $seeder->setCommand($this->command);
        }

        $company = $seeder->seed($creator);

        $this->command?->info("Real company: {$company->name} ({$company->slug})");
    }

    /** Convenience for callers that just want the identifiers. */
    public static function identifiers(): array
    {
        return [
            'name' => self::COMPANY_NAME,
            'slug' => self::COMPANY_SLUG,
            'admin' => self::SUPER_ADMIN_EMAIL,
        ];
    }
}
