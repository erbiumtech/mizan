<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements — docs/saas-launch-plan.md §7.6.
 *
 * One table. `audience_kind` is `all` or `department`; `audience_value` carries
 * the department name when it is one — a string, because departments are an
 * option list and not a table. Unpublished until `published_at` is set, and
 * gone from the dashboard after `expires_at`; the row stays for the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->string('audience_kind', 20)->default('all');
            $table->string('audience_value')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
