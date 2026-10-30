<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's half of a review — docs/saas-launch-plan.md §14.6.
 *
 * Whether a cycle asks for a self-review is the cycle's own choice, on by default: every cycle that
 * exists already is backfilled to "yes" by the column default, which is what §14.6 always described.
 * The review records when the employee submitted their half and what they said beside the ratings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('review_cycles', 'includes_self_review')) {
            Schema::table('review_cycles', function (Blueprint $table): void {
                $table->boolean('includes_self_review')->default(true);
            });
        }

        Schema::table('reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('reviews', 'self_submitted_at')) {
                $table->timestamp('self_submitted_at')->nullable();
            }

            if (! Schema::hasColumn('reviews', 'self_comments')) {
                $table->text('self_comments')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn(['self_submitted_at', 'self_comments']);
        });

        Schema::table('review_cycles', function (Blueprint $table): void {
            $table->dropColumn('includes_self_review');
        });
    }
};
