<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field visits, judged — docs/saas-launch-plan.md §14.10.
 *
 * The coordinates have been recorded on both ends since §8.5 and nothing has ever
 * looked at them. What was missing is something to judge them *against*: a contact
 * carries an address, not a point, and the model said so.
 *
 * `field_visit_places` is that point, kept here rather than on the contact or the
 * lead. Invoicing owns contacts and CRM may not add a column to them; a place keyed
 * on the morph the visit already uses works for a lead, an opportunity and a contact
 * alike, and belongs to the module that needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_visit_places', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // How far from the pin still counts as "there". A shop front is twenty metres;
            // a factory with its office at the back of the yard is two hundred.
            $table->unsignedInteger('radius_m')->default(150);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('pinned_by')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('field_visit_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->timestamps();
        });

        Schema::table('field_visits', function (Blueprint $table): void {
            // What the check-in was judged to be. Null where nothing judged it — no pin,
            // or the check was switched off — which is not the same as "away".
            $table->string('in_verdict', 16)->nullable()->after('in_accuracy_m');
            $table->unsignedInteger('in_distance_m')->nullable()->after('in_verdict');
            $table->string('photo_path')->nullable()->after('notes');
            // What the client said on the confirmation page, where they said anything.
            $table->unsignedTinyInteger('rating')->nullable()->after('confirmed_name');
            $table->string('rating_comment')->nullable()->after('rating');
        });

        /*
         * Refused clock-ins, which have never been recorded anywhere.
         *
         * A refusal currently throws and vanishes. That is the wrong half of the story to
         * keep: a rep standing in the right place whose phone put them three hundred
         * metres away has a problem the company should be able to see, and so does a rep
         * who tries from home every morning.
         */
        Schema::create('field_visit_refusals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('field_visit_id')->nullable()->constrained('field_visits')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reason', 40);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_visit_refusals');

        Schema::table('field_visits', fn (Blueprint $table) => $table->dropColumn([
            'in_verdict', 'in_distance_m', 'photo_path', 'rating', 'rating_comment',
        ]));

        Schema::dropIfExists('field_visit_staff');
        Schema::dropIfExists('field_visit_places');
    }
};
