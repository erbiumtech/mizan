<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An announcement aimed by rule — docs/saas-launch-plan.md §8.9. Badges and tasks already carry theirs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->json('audience_rule')->nullable()->after('audience_value');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn('audience_rule');
        });
    }
};
