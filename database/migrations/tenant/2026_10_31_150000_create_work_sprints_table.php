<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprints, and the column on an item that says which one it is in — docs/project-management-plan.md §6.2. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sprints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('goal')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('state', 10)->default('future')->comment('future|active|closed');

            /*
             * When it actually ran, which is not the same as the dates it was planned for.
             *
             * Velocity is counted between these two rather than between `starts_on` and `ends_on`: a
             * sprint started two days late and closed on time delivered what it delivered in the
             * days it had, and measuring it against the plan would flatter or punish the team for
             * the calendar rather than the work.
             */
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'state']);
        });

        Schema::table('work_items', function (Blueprint $table): void {
            $table->foreignId('sprint_id')->nullable()->after('parent_id')
                ->constrained('work_sprints')->nullOnDelete();

            /*
             * How many sprints this has been carried out of.
             *
             * The number a velocity chart needs to be honest about: a sprint that "delivered"
             * forty points having carried fifteen of them in from the last one is a different
             * sprint from one that started them. Incremented when a sprint closes with the item
             * unfinished, and never reset.
             */
            $table->unsignedSmallInteger('carried_over')->default(0)->after('sprint_id');

            $table->index('sprint_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sprint_id');
            $table->dropColumn('carried_over');
        });

        Schema::dropIfExists('work_sprints');
    }
};
