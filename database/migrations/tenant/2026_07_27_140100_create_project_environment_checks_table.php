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

            $table->index(['project_environment_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment_checks');
    }
};
