<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();      // tenant scope (spatie team)
            $table->unsignedBigInteger('user_id')->nullable()->index(); // owner; null for global
            $table->string('resource');                             // List page / resource key
            $table->string('name');
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_public')->default(false);           // shared with the company
            $table->boolean('is_global')->default(false);           // admin-pinned for everyone
            $table->boolean('is_default')->default(false);          // owner's default for this table
            $table->json('state');                                  // filters/columns/sort/search/grouping
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'resource']);
            $table->index(['company_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};
