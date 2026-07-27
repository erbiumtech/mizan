<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('PRJ-ERP-01');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('planned')->comment('planned|active|on_hold|completed|cancelled');
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete()
                ->comment('Primary manager');
            $table->foreignId('secondary_employee_id')->nullable()->constrained('employees')->nullOnDelete()
                ->comment('Secondary manager — stand-in for the primary');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('project_employee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role')->nullable()->comment('Job role on this project, e.g. Backend lead');
            $table->decimal('allocation_pct', 5, 2)->nullable();
            $table->date('from_date');
            $table->date('to_date')->nullable()->comment('Null = open stint');
            $table->timestamps();

            $table->unique(['project_id', 'employee_id', 'from_date']);
        });

        Schema::create('project_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('kind')->comment('prod|qual|dev');
            $table->string('url')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable()->comment('Plain text by decision — see docs/projects-listing-plan.md §4');
            $table->text('notes')->nullable();

            // Denormalised latest health result so the listing needs no aggregate.
            $table->boolean('is_monitored')->default(true);
            $table->string('health_status')->nullable()->comment('up|down|unknown');
            $table->unsignedSmallInteger('health_code')->nullable();
            $table->unsignedInteger('health_latency_ms')->nullable();
            $table->string('health_error')->nullable();
            $table->timestamp('health_checked_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environments');
        Schema::dropIfExists('project_employee');
        Schema::dropIfExists('projects');
    }
};
