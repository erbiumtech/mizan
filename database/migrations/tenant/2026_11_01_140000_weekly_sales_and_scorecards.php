<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The weekly sales report, and scorecard profiles — docs/saas-launch-plan.md §14.10. */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A rep's week, where CRM does not already know it.
         *
         * A company with the CRM module has every figure already — visits, activities,
         * quotes, deals — and the report reads them. A company without it has a sales
         * team and no pipeline, and the choice is a short form each Friday or no report
         * at all. The form is the short one deliberately: five numbers and a sentence is
         * something somebody fills in; twenty is something they stop filling in.
         */
        Schema::create('weekly_sales_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // The Monday of the week, so a week is one value and two people cannot
            // disagree about which week they mean.
            $table->date('week_starting');
            $table->unsignedSmallInteger('visits')->default(0);
            $table->unsignedSmallInteger('calls')->default(0);
            $table->unsignedSmallInteger('quotes')->default(0);
            $table->unsignedSmallInteger('orders')->default(0);
            $table->decimal('order_value', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'week_starting']);
        });

        /*
         * How a designation's score is weighted — §14.10.
         *
         * The merit ledger already records what happened and what each thing was worth.
         * What it cannot know is that a salesman's week should count volume more heavily
         * than a draughtsman's, and that is a decision per designation rather than per
         * person: a company that set it per person would be setting it forty times and
         * arguing about it forty times.
         */
        Schema::create('scorecard_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('designation', 120)->unique();
            $table->json('weights');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scorecard_profiles');
        Schema::dropIfExists('weekly_sales_reports');
    }
};
