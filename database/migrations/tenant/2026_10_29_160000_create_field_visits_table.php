<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Field visits — docs/saas-launch-plan.md §8.5: a next action with a location and a witness. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->dateTime('planned_at');
            $table->dateTime('checked_in_at')->nullable();
            $table->decimal('in_latitude', 10, 7)->nullable();
            $table->decimal('in_longitude', 10, 7)->nullable();
            $table->unsignedInteger('in_accuracy_m')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->decimal('out_latitude', 10, 7)->nullable();
            $table->decimal('out_longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->string('confirmation_sent_to')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('confirmed_name')->nullable();
            $table->foreignId('next_action_id')->nullable()->constrained('next_actions')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['employee_id', 'planned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_visits');
    }
};
