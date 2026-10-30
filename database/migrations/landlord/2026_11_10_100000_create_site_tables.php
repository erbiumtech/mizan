<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public site's hand-written content — docs/company-website-plan.md W1.
 *
 * Landlord, because the site belongs to the installation and not to any company: there is no
 * tenant on the pages that read these, and the platform panel that edits them has no tenant
 * connection. Everything generated (plans, modules) is config and has no table.
 *
 * `site_pages.body` is Markdown as typed; it is rendered on read, never stored as HTML, so what
 * the editor sees in the form is what the database holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 160);
            $table->string('meta_description', 160)->nullable();
            $table->text('body');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 120)->nullable();
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 12)->default('new');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('site_settings');
    }
};
