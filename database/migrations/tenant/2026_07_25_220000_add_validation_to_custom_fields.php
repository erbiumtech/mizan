<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->decimal('min', 20, 4)->nullable()->after('is_required'); // min value / min length
            $table->decimal('max', 20, 4)->nullable()->after('min');         // max value / max length
            $table->string('regex')->nullable()->after('max');               // regex pattern (no delimiters)
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['min', 'max', 'regex']);
        });
    }
};
