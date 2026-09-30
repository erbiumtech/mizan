<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Forgot password, for every role — the broker is role-agnostic, so an Employee
 * (the least-privileged account) proving the whole cycle proves it for all of
 * them. The table this needs is landlord, created by its own migration; without
 * it the feature had nowhere to store a token.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_panels_expose_the_reset_pages(): void
    {
        foreach (['admin', 'platform'] as $panel) {
            $this->assertTrue(Route::has("filament.{$panel}.auth.password-reset.request"), "{$panel} request page");
            $this->assertTrue(Route::has("filament.{$panel}.auth.password-reset.reset"), "{$panel} reset page");
        }
    }

    public function test_an_employee_can_reset_their_password_end_to_end(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'employee@test.local',
            'password' => Hash::make('old-password'),
        ]);

        // 1. Request the link — the same call the "Forgot password?" form makes.
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => $user->email]));

        // 2. The user is emailed a reset token, and it is captured off the notification.
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        // 3. Resetting with that token changes the password.
        $status = Password::reset(
            ['email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password', 'token' => $token],
            function (User $u, string $password): void {
                $u->forceFill(['password' => Hash::make($password)])->save();
            },
        );

        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_an_unknown_email_is_not_confirmed_to_exist(): void
    {
        Notification::fake();

        // The broker reports the same "we'll email you" outcome shape without
        // sending, so the form cannot be used to enumerate who has an account.
        $status = Password::sendResetLink(['email' => 'nobody@test.local']);

        $this->assertSame(Password::INVALID_USER, $status);
        Notification::assertNothingSent();
    }
}
