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
            // User ko email se dhoondo
            $user = User::where('email', $request->email)->first();

            // 1. Agar status 0 hai to inactive ka error do
            if ($user && $user->status == 0) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('Your account is inactive. Please contact the administrator.'),
                ]);
            }

            // 2. Agar status 1 hai aur password sahi hai to login pass karo
            if ($user && Hash::check($request->password, $user->password)) {
                return $user;
            }

            return null;
        });
    }
}
