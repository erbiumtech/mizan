<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_environment_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_environment_id')->constrained('project_environments')->cascadeOnDelete();
            $table->timestamp('started_at')->comment('First failure of the run, not the threshold crossing');
            $table->timestamp('confirmed_at')->nullable()->comment('Crossed the failure threshold and alerted');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('failure_count')->default(1);
            $table->string('last_error')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamps();

            $table->index(['project_environment_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment_incidents');
    }
};
