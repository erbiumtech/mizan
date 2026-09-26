<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditional visibility (show this field only when another field has a given
 * value) and at-rest encryption for custom field definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->string('visible_when_field')->nullable(); // another field's code on the same model
            $table->string('visible_when_value')->nullable(); // the value that reveals this field
            $table->boolean('is_encrypted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['visible_when_field', 'visible_when_value', 'is_encrypted']);
        });
    }
};
