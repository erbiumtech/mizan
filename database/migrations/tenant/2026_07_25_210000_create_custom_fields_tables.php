<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant custom fields (EAV). Definitions and values live in the tenant
 * database, so each company has its own set — DB-per-tenant provides isolation
 * (no tenant_id column needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');            // e.g. App\Modules\Invoicing\Models\Contact
            $table->string('code');                  // machine key, unique per model
            $table->string('name');                  // label
            $table->string('type')->default('text'); // text|textarea|number|date|boolean|select
            $table->json('options')->nullable();     // for select
            $table->boolean('is_required')->default(false);
            $table->text('help')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['model_type', 'code']);
            $table->index(['model_type', 'is_active']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'entity_type', 'entity_id'], 'cfv_unique');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
