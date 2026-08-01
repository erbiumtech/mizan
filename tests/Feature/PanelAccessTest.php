<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_access_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');
        $user = User::factory()->create();

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_inactive_user_cannot_access_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');
        $user = User::factory()->inactive()->create();

        $this->assertFalse($user->canAccessPanel($panel));
    }
}
