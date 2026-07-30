<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Modules\Invoicing\Models\Contact;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\ContactSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The default seed run must not carry real people or trading partners: the repo
 * is shared, and a fresh install or demo should never come up holding staff
 * names, salaries or customers. The genuine values live in
 * Database\Seeders\Production and are only run when named explicitly.
 */
class SeederProducesDummyDataTest extends TestCase
{
    use RefreshDatabase;

    /** Names and domains that must not appear in the default seeders. */
    private const REAL_MARKERS = [
        '@erbium.ch',
        'erbium.tech',
        'ERBIUMTECH',
        '4sure',
        '@gmail.com',
    ];

    /** @return array<int, string> default (non-Production) seeder files */
    private function defaultSeeders(): array
    {
        return array_filter(
            File::files(database_path('seeders')),
            fn ($file) => $file->getExtension() === 'php',
        );
    }

    public function test_no_default_seeder_contains_real_identifiers(): void
    {
        $offenders = [];

        foreach ($this->defaultSeeders() as $file) {
            $contents = File::get($file->getPathname());

            foreach (self::REAL_MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    $offenders[] = $file->getFilename().' contains "'.$marker.'"';
                }
            }
        }

        $this->assertSame([], $offenders, "Real data leaked back into the default seeders:\n - ".implode("\n - ", $offenders));
    }

    public function test_the_real_data_is_still_available_under_production(): void
    {
        $real = File::get(database_path('seeders/Production/RealEmployeeSeeder.php'));

        $this->assertStringContainsString('@erbium.ch', $real, 'the real roster must be preserved, not deleted');
        $this->assertStringContainsString('Database\Seeders\Production', $real);
    }

    /** Production seeders must not be wired into the default run. */
    public function test_the_default_run_does_not_call_the_production_seeders(): void
    {
        $this->assertStringNotContainsString(
            'Production\\',
            File::get(database_path('seeders/DatabaseSeeder.php'))
        );
    }

    public function test_the_dummy_company_and_admin_are_placeholders(): void
    {
        $this->assertStringContainsString('example.test', DatabaseSeeder::SUPER_ADMIN_EMAIL);
        $this->assertSame('demo-company', CompanySeeder::COMPANY_SLUG);
        $this->assertStringNotContainsString('ERBIUMTECH', CompanySeeder::COMPANY_NAME);
    }

    /** The real values can still be supplied per-environment. */
    public function test_env_overrides_win_over_the_dummy_defaults(): void
    {
        // config, not env(): read at the point of use, env() returns null once
        // the host has run `config:cache`.
        config([
            'seeding.admin_email' => 'real-admin@somewhere.test',
            'seeding.company_slug' => 'real-slug',
        ]);

        $this->assertSame('real-admin@somewhere.test', DatabaseSeeder::superAdminEmail());
        $this->assertSame('real-slug', CompanySeeder::companySlug());

        config(['seeding.admin_email' => null, 'seeding.company_slug' => null]);

        $this->assertSame(DatabaseSeeder::SUPER_ADMIN_EMAIL, DatabaseSeeder::superAdminEmail());
    }

    /** The dummy seeders must still produce a working, self-consistent dataset. */
    public function test_the_dummy_employee_and_contact_seeders_run(): void
    {
        $this->seed(PermissionSeeder::class);

        // No makeCurrent(): the suite runs a single database, so tenant models
        // already resolve to the default connection. Only the permission team
        // context matters, for the role assignment inside EmployeeSeeder.
        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        (new EmployeeSeeder)->run();
        (new ContactSeeder)->run();

        $this->assertSame(16, Employee::count());
        $this->assertSame(5, Contact::count());

        // Every seeded employee login is a placeholder address.
        $realish = User::whereIn('id', Employee::pluck('user_id'))
            ->where('email', 'not like', '%@example.test')
            ->pluck('email')
            ->all();

        $this->assertSame([], $realish, 'seeded employee logins must all use example.test');

        // The reporting hierarchy survived the rewrite — six people have a manager.
        $this->assertSame(6, Employee::whereNotNull('manager_id')->count());
    }

    /** InvoiceSeeder looks contacts up by name; those names must still resolve. */
    public function test_the_invoice_seeder_still_finds_its_contacts(): void
    {
        (new ContactSeeder)->run();

        foreach ([
            ContactSeeder::CUSTOMER_PRIMARY,
            ContactSeeder::CUSTOMER_SECONDARY,
            ContactSeeder::SUPPLIER_HARDWARE,
        ] as $name) {
            $this->assertNotNull(
                Contact::where('name', $name)->first(),
                "InvoiceSeeder expects a contact named {$name}"
            );
        }
    }
}
