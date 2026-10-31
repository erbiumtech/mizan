<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The thread summary — docs/project-management-plan.md §12, assist 2.
 *
 * Stored, because it is regenerated on request rather than on every page load, and stamped, so
 * the page can say *generated, three days ago* beside it. The other two assists write nothing:
 * a draft is a prefilled form and an estimate is a number beside the field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->text('ai_summary')->nullable()->after('imported_from');
            $table->timestamp('ai_summary_at')->nullable()->after('ai_summary');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn(['ai_summary', 'ai_summary_at']));
    }
};
