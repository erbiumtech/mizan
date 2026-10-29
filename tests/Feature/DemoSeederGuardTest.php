<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demo data never lands on a production host — security audit 2026-10-26
 * finding 14, docs/saas-launch-plan.md §9. The landlord half still seeds, with
 * the real password the seeder already insists on there.
 */
class DemoSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeds_the_super_admin_and_no_demo_company(): void
    {
        $this->app['env'] = 'production';
        config(['seeding.admin_password' => 'a-real-password']);

        // --force answers Laravel's own "application in production" prompt; the guard
        // under test is the seeder's, which runs after that and asks nobody.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->expectsOutputToContain('demo companies and demo data were not')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', DatabaseSeeder::superAdminEmail())->where('is_super_admin', true)->exists());
        $this->assertSame(0, Company::count(), 'A demo company was seeded on a production host.');
    }
}
