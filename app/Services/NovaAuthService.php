<?php

namespace App\Services;

use App\Models\User;
use Laravel\Fortify\Fortify;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class NovaAuthService
{
    /**
     * Handle the custom authentication logic for Laravel Nova/Fortify.
     */
    public function handleStatusBasedLogin(): void
    {
        //Register a callback that is responsible for validating incoming authentication credentials
        Fortify::authenticateUsing(function ($request) {
            
            $user = User::where('email', $request->email)->first();

            if ($user && $user->status == 0) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('Your account is inactive. Please contact the administrator.'),
                ]);
            }

            if ($user && Hash::check($request->password, $user->password)) {
                return $user;
            }

            return null;
        });
    }
}
