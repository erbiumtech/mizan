<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-user preference endpoint — the server-side home for the table context menu's
 * off switch (docs/table-context-menu-plan.md Phase 4, revisited).
 *
 * The allow-list is the security posture worth pinning: anything with a session can POST
 * here, so the column must only ever hold keys this application declared.
 */
class UserPreferencesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_write_preferences(): void
    {
        $this->postJson('/user/preferences', ['key' => 'tableContextMenuDisabled', 'value' => true])
            ->assertUnauthorized();
    }

    public function test_the_flag_is_stored_per_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/user/preferences', ['key' => 'tableContextMenuDisabled', 'value' => true])
            ->assertNoContent();

        $this->assertTrue($user->fresh()->preferences['tableContextMenuDisabled']);
    }

    public function test_turning_back_on_stores_false_and_keeps_other_keys(): void
    {
        $user = User::factory()->create();
        $user->preferences = ['someFutureFlag' => 'kept'];
        $user->save();

        $this->actingAs($user)
            ->postJson('/user/preferences', ['key' => 'tableContextMenuDisabled', 'value' => false])
            ->assertNoContent();

        $preferences = $user->fresh()->preferences;

        $this->assertFalse($preferences['tableContextMenuDisabled']);
        $this->assertSame('kept', $preferences['someFutureFlag'], 'a write must merge, not replace');
    }

    public function test_a_key_outside_the_allow_list_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/user/preferences', ['key' => 'is_super_admin', 'value' => true])
            ->assertUnprocessable();

        $this->assertNull($user->fresh()->preferences);
    }

    public function test_a_non_boolean_value_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/user/preferences', ['key' => 'tableContextMenuDisabled', 'value' => 'sideways'])
            ->assertUnprocessable();
    }
}
