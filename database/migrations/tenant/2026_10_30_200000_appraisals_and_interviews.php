<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Review templates with weighted competencies, and interviews with a panel — docs/saas-launch-plan.md §14.6. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('review_competencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_template_id')->constrained('review_templates')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->decimal('weight', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('review_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('review_competency_id')->constrained('review_competencies')->cascadeOnDelete();
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->string('self_note')->nullable();
            $table->string('manager_note')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'review_competency_id'], 'unique_rating_per_competency');
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('review_template_id')->nullable()->after('review_cycle_id')->constrained('review_templates')->nullOnDelete();
            $table->boolean('promotion_recommended')->default(false)->after('final_rating');
            $table->string('promotion_designation')->nullable()->after('promotion_recommended');
        });

        Schema::create('interview_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('recommendation', 12)->nullable();
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['interview_id', 'user_id']);
        });

        Schema::table('interviews', function (Blueprint $table): void {
            $table->json('interviewer_user_ids')->nullable()->after('interviewer_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('interviews', fn (Blueprint $t) => $t->dropColumn('interviewer_user_ids'));
        Schema::dropIfExists('interview_feedback');
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('review_template_id');
            $table->dropColumn(['promotion_recommended', 'promotion_designation']);
        });
        Schema::dropIfExists('review_ratings');
        Schema::dropIfExists('review_competencies');
        Schema::dropIfExists('review_templates');
    }
};
