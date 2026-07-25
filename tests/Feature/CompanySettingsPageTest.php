<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySettings;
use App\Models\User;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CompanySettingsPageTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_admin_can_render_and_save_settings(): void
    {
        Role::findOrCreate('Administrator', 'web');
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        $this->actingAs($user);
        $this->setCurrentTenant();

        Livewire::test(CompanySettings::class)
            ->assertSuccessful()
            ->set('data.petty_cash_float_amount', 8888)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(8888.0, app(TenantSettings::class)->get('petty_cash.float_amount'), 0.001);
    }

    public function test_non_admin_cannot_access(): void
    {
        $this->assertFalse(CompanySettings::canAccess());
    }
}
