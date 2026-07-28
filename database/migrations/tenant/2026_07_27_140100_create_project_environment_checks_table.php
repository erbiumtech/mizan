<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_environment_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_environment_id')->constrained('project_environments')->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->boolean('is_up');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error')->nullable();

            // Named explicitly: the conventional name Laravel would generate
            // ("project_environment_checks_project_environment_id_checked_at_index")
            // is 66 characters, over MySQL's 64-character identifier limit.
            $table->index(['project_environment_id', 'checked_at'], 'proj_env_checks_env_checked_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment_checks');
    }
};
