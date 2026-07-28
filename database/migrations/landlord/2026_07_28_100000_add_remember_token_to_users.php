<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the `remember_token` column that Laravel's session guard needs.
 *
 * The users table in this project was hand-written and dropped Laravel's
 * `rememberToken()`, so signing in with "Remember me" ticked — the checkbox
 * Filament's login page shows by default — died on
 * "Unknown column 'remember_token' in 'field list'". Logging out cycles the same
 * column.
 *
 * Guarded with `hasColumn` so it is safe to run against a database where the
 * column has already been added by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'remember_token')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Nullable 100-char string, exactly as Laravel's own helper defines
            // it — the guard writes a 60-character random string.
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'remember_token')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }
};
