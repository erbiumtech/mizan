<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Self-referential reporting line. Nullable: top of the org has no
            // manager. Soft index (no FK) so removing a manager row never
            // cascades or blocks — integrity is guarded in the app layer.
            $table->unsignedBigInteger('manager_id')->nullable()->after('user_id');
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
