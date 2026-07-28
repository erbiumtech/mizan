<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Signing in with "Remember me" — the checkbox Filament's login page shows by
 * default — makes the session guard write a token to `users.remember_token`.
 *
 * The project's hand-written users migration had dropped Laravel's
 * `rememberToken()`, so that write failed in production with
 * "Unknown column 'remember_token' in 'field list'". Nothing in the suite
 * exercised it, because `actingAs()` never remembers.
 */
class RememberMeLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_users_table_has_a_remember_token_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'remember_token'),
            'the session guard cannot remember a login without this column'
        );
    }

    public function test_logging_in_with_remember_me_persists_a_token(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->remember_token);

        Auth::guard('web')->login($user, remember: true);

        $token = $user->fresh()->remember_token;
        $this->assertNotNull($token, 'remember_token should have been written');
        $this->assertSame(60, mb_strlen($token));
    }

    public function test_logging_out_cycles_the_token(): void
    {
        $user = User::factory()->create();

        Auth::guard('web')->login($user, remember: true);
        $first = $user->fresh()->remember_token;

        Auth::guard('web')->logout();

        $second = $user->fresh()->remember_token;
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second, 'logging out must invalidate the old token');
    }

    /** The column is hidden, so it must never reach a JSON payload. */
    public function test_the_token_is_not_exposed_when_serialised(): void
    {
        $user = User::factory()->create();
        Auth::guard('web')->login($user, remember: true);

        $this->assertArrayNotHasKey('remember_token', $user->fresh()->toArray());
    }
}
