<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second factor for the accounts that can move money.
 *
 * Landlord, because `users` is: one login reaches every company a person is a member of, so the factor
 * protecting it is the person's, not any company's. Both columns hold what Filament's app-authentication
 * provider writes — a TOTP secret and a set of one-time recovery codes — and both are cast `encrypted` by
 * the traits on the model, so a database dump does not hand out the secrets it protects.
 *
 * Text rather than string: an encrypted payload is longer than the value it wraps, and the recovery codes
 * are a JSON list of several.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
