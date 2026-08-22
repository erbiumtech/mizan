<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\NavigationBadge;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The sidebar's counts are cached, and who they are cached for.
 *
 * The saving is the easy half. The half worth a test is that a cached count
 * never reaches anybody it was not counted for: several of these figures are
 * scoped — LeaveRequestResource counts through `getEloquentQuery()`, which for a
 * manager is filtered to their own downline — and all of them are a company's
 * private numbers. A key that left out the company or the user would show one
 * company's pending approvals to another with nothing failing anywhere.
 *
 * Note the array cache store the suite runs on does not apply `cache.prefix`,
 * so spatie's per-tenant prefixing is no help here and these tests would catch a
 * key that leaned on it. That is deliberate: production leans on the key.
 *
 * See docs/page-load-performance-plan.md.
 */
class NavigationBadgeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();
    }

    private function actAs(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_super_admin' => true, 'status' => 1]);
        // syncWithoutDetaching rather than attach: setCurrentTenant() adopts every
        // user the test has already made, so a second person here is a member
        // before this line runs.
        $this->company->users()->syncWithoutDetaching([$user->getKey()]);
        $user->assignRole('Administrator');

        $this->actingAs($user);

        return $user;
    }

    /** A resource class name; nothing is loaded from it, it is only the key. */
    private const RESOURCE = 'App\\Modules\\Leave\\Filament\\Resources\\LeaveRequests\\LeaveRequestResource';

    public function test_a_count_runs_once_and_is_then_served_from_the_cache(): void
    {
        $this->actAs('one@test.local');
        $this->setCurrentTenant($this->company);

        $runs = 0;
        $count = function () use (&$runs): int {
            $runs++;

            return 7;
        };

        $this->assertSame('7', NavigationBadge::of(self::RESOURCE, $count));
        $this->assertSame('7', NavigationBadge::of(self::RESOURCE, $count));

        $this->assertSame(1, $runs, 'the count ran twice, so nothing is being cached');
    }

    public function test_zero_hides_the_badge_rather_than_showing_a_nought(): void
    {
        $this->actAs('two@test.local');
        $this->setCurrentTenant($this->company);

        $this->assertNull(NavigationBadge::of(self::RESOURCE, fn (): int => 0));
    }

    /**
     * The one that matters. Two people in the same company, and one of them has
     * a count the other must not be handed.
     */
    public function test_one_users_count_is_never_served_to_another(): void
    {
        $this->actAs('manager@test.local');
        $this->setCurrentTenant($this->company);

        $this->assertSame('3', NavigationBadge::of(self::RESOURCE, fn (): int => 3));

        // A different person, same company, same resource, same request cycle.
        $this->actAs('clerk@test.local');

        $this->assertSame('9', NavigationBadge::of(self::RESOURCE, fn (): int => 9));
    }

    public function test_one_companys_count_is_never_served_to_another(): void
    {
        $this->actAs('shared@test.local');
        $this->setCurrentTenant($this->company);

        $this->assertSame('4', NavigationBadge::of(self::RESOURCE, fn (): int => 4));

        $other = Company::factory()->create();
        $other->users()->attach(auth()->id());
        Filament::setTenant($other);

        $this->assertSame('11', NavigationBadge::of(self::RESOURCE, fn (): int => 11));
    }

    /**
     * Outside a request there is nobody to key on — a console command, a queued
     * job — and the count runs every time rather than being filed under nobody
     * and handed to the next caller.
     */
    public function test_without_a_company_or_a_user_nothing_is_cached(): void
    {
        Filament::setTenant(null);

        $runs = 0;
        $count = function () use (&$runs): int {
            $runs++;

            return 5;
        };

        $this->assertSame('5', NavigationBadge::of(self::RESOURCE, $count));
        $this->assertSame('5', NavigationBadge::of(self::RESOURCE, $count));

        $this->assertSame(2, $runs);
    }
}
