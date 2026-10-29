<?php

namespace Tests\Feature;

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The sign-in log — docs/saas-launch-plan.md §7.7. Three rows on the landlord
 * activity log, no table; a failed attempt is logged against the email typed.
 */
class SignInLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_and_out_are_logged_against_the_user_with_the_address(): void
    {
        $user = User::factory()->create(['status' => 1]);

        Auth::login($user);
        Auth::logout();

        $login = ActivityLog::query()->where('log_name', 'Auth')->where('event', 'login')->first();
        $this->assertNotNull($login, 'No sign-in row.');
        $this->assertSame($user->getKey(), (int) $login->causer_id);
        $this->assertSame($user->getKey(), (int) $login->subject_id);
        $this->assertNull($login->company_id, 'A sign-in claimed a company.');
        $this->assertArrayHasKey('ip', $login->properties->toArray());
        $this->assertArrayHasKey('user_agent', $login->properties->toArray());

        $this->assertSame(1, ActivityLog::query()->where('log_name', 'Auth')->where('event', 'logout')->count());
    }

    public function test_a_failed_attempt_is_logged_against_the_email_and_no_user(): void
    {
        User::factory()->create(['email' => 'ayesha@example.test', 'status' => 1]);

        $this->assertFalse(Auth::attempt(['email' => 'Ayesha@Example.test', 'password' => 'wrong']));
        $this->assertFalse(Auth::attempt(['email' => 'nobody@example.test', 'password' => 'wrong']));

        $failures = ActivityLog::query()->where('log_name', 'Auth')->where('event', 'failed')->orderBy('id')->get();
        $this->assertCount(2, $failures);
        $this->assertSame('ayesha@example.test', $failures[0]->properties['email']);
        $this->assertSame('Failed sign-in for ayesha@example.test', $failures[0]->description);
        $this->assertSame('nobody@example.test', $failures[1]->properties['email']);
        $this->assertNull($failures[1]->causer_id);
        $this->assertNull($failures[1]->subject_id);
    }
}
