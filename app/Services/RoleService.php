<?php

namespace App\Services;

use App\Models\User;

class RoleService
{
    // Central array for role slugs
    public const ROLES = [
        'ADMIN' => 'admin',
        'USER'  => 'user',
    ];

    /**
     * Check if user is an Admin
     */
    public function isAdmin(User $user): bool
    {
        return $user->roles()->where('slug', self::ROLES['ADMIN'])->exists();
    }

    /**
     * Check if user is a normal User
     */
    public function isUser(User $user): bool
    {
        return $user->roles()->where('slug', self::ROLES['USER'])->exists();
    }
}
