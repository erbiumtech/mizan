<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browsers and apps subscribed to push — docs/desktop-mobile-plan.md A3. Landlord-side because a device
 * belongs to a user, and users live here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('transport', 16)->default('webpush');
            // A push-service URL runs to several hundred characters; 2048 is the practical ceiling, and the
            // unique index is on a hash-length prefix so a long endpoint still fits one.
            $table->string('endpoint', 2048);
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255)->nullable();
            $table->string('auth', 255)->nullable();
            $table->string('content_encoding', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'transport']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
