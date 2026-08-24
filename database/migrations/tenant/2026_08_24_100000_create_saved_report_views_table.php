<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's saved filters for one report — `docs/reports-expansion-plan.md` Phase 4.5.
 *
 * "The filters somebody uses every month, kept. The URL already carries the whole state, so this is storage
 * rather than plumbing."
 *
 * **The date is deliberately not one of the stored filters, and that sentence of the plan is why.** What
 * somebody uses every month is the *filters*; the date is the one thing that changes every month. A saved
 * view holding 30 June would open on 30 June for ever, and the person who saved it would not notice for a
 * while — which is the worst kind of wrong on a report.
 *
 * For a fixed date there is already a mechanism and it is better than this one: the URL carries the whole
 * state, which is 4c's own premise, so "the balance sheet at 30 June" is a link somebody sends. Two
 * mechanisms doing one job each — a link for a moment, a saved view for a habit.
 *
 * **Per user, not per company.** These are somebody's own working filters, not a company policy, and Phase 7
 * stores per-user dashboard layouts the same way. No `company_id`: the table is on the tenant connection, so
 * the company is the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_report_views', function (Blueprint $table) {
            $table->id();

            // Not a foreign key to `users`: that table lives on the landlord connection, and a constraint
            // across connections is not a constraint. Indexed instead, and the model scopes every read to
            // the signed-in id — which is what actually keeps one person's views out of another's list.
            $table->unsignedBigInteger('user_id')->index();

            // The Reports hub's own catalogue key — a class basename, the same string that travels in the
            // URL and in `ReportRenderers`. Not a foreign key to anything: a report is code, not a row.
            $table->string('report_key');

            $table->string('name');

            /*
             * The filters, as the pane understands them: the comparison basis and whichever of account,
             * budget, month and search the report asks for.
             *
             * JSON rather than a column each, because the *set* differs per report — see ReportPane::ASKS —
             * and a column per possible filter would be five nullable columns of which any given report uses
             * at most one. A new filter would then be a migration rather than a key.
             */
            $table->json('state');

            $table->timestamps();

            // Saving over a name replaces it rather than growing a second view called the same thing, which
            // is what somebody adjusting last month's filters means by pressing save again.
            $table->unique(['user_id', 'report_key', 'name'], 'saved_report_views_unique_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_report_views');
    }
};
