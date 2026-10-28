<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve signup — docs/saas-launch-plan.md §5.
 *
 * `users.email_verified_at` did not exist: every user so far was created by a
 * person, from the panel or a seeder, and nothing asked them to prove a mailbox.
 * Nullable with no backfill, so every existing user stays exactly as reachable as
 * before — only the signup door reads it, and the panels never enforce it.
 *
 * A `signups` row is a company that does not exist yet: the name and profile the
 * visitor typed, waiting for the mailbox to be proven. Nothing is provisioned from
 * it until `verify` dispatches the job, and the row outlives the job so the
 * "preparing your workspace" page has something to poll and a failure has
 * somewhere to land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        Schema::create('signups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company_name');
            $table->string('profile')->nullable();
            $table->timestamp('provisioning_started_at')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signups');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
