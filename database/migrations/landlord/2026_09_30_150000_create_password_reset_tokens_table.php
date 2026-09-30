<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table Laravel's password broker writes reset tokens to — a default Laravel
 * migration that never made it into this app's landlord/tenant paths, so the
 * table did not exist and "forgot password" had nowhere to store its token.
 *
 * Landlord, because it is keyed on the user's email and users live in the
 * landlord database: a reset is about an identity, which is company-independent,
 * so the token belongs beside `users` on the default connection the broker uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
