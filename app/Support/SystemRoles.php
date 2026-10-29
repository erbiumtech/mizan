<?php

namespace App\Support;

/**
 * The five roles the seeder composes, and what may be done to them —
 * docs/saas-launch-plan.md §14.9.
 *
 * There is no `is_system` column, and adding one would be a migration that says what
 * `RoleSeeder` already says. These are system roles because the seeder creates them,
 * every module's manifest grants permissions to them by name, and `RoleSeeder` runs
 * again on every deploy — so a renamed or deleted one comes back, and the company
 * that renamed it has two.
 *
 * **Locked means renamed and deleted, not edited.** A company tightening what its own
 * Accountant may do is doing exactly what the permissions screen is for; the seeder
 * grants and never revokes, so that choice survives the next deploy. What it may not
 * do is move the name out from under eleven manifests.
 */
final class SystemRoles
{
    /** @var array<int, string> */
    public const NAMES = ['Administrator', 'CEO', 'Manager', 'Accountant', 'Employee'];

    public static function isSystem(?string $name): bool
    {
        return in_array((string) $name, self::NAMES, true);
    }

    /** A name for a copy of this role that no company already has. */
    public static function copyNameFor(string $name, callable $taken): string
    {
        $base = $name.' (copy)';

        if (! $taken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 50; $suffix++) {
            $candidate = $name.' (copy '.$suffix.')';

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return $name.' '.uniqid();
    }
}
