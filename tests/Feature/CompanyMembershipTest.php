<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_lists_only_companies_they_belong_to(): void
    {
        $user = User::factory()->create();
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $other = Company::factory()->create();

        $user->companies()->attach([$a->id, $b->id]);

        $panel = Filament::getPanel('admin');
        $tenants = $user->getTenants($panel)->pluck('id')->all();

        sort($tenants);
        $this->assertSame([$a->id, $b->id], $tenants);
        $this->assertNotContains($other->id, $tenants);
    }

    public function test_can_access_tenant_reflects_membership(): void
    {
        $user = User::factory()->create();
        $member = Company::factory()->create();
        $stranger = Company::factory()->create();

        $user->companies()->attach($member->id);

        $this->assertTrue($user->canAccessTenant($member));
        $this->assertFalse($user->canAccessTenant($stranger));
    }
}
