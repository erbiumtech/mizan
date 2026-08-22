<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work in progress — `docs/construction-management-plan.md` §4.4.
 *
 * **"The one stored total in this plan that is not a performance materialisation."** Every other total in this suite is
 * computed, and `docs/new-module-checklist.md` §10 is the house rule that says so. §4.4 argues the exception directly and
 * the argument is worth having in front of anybody who reads this file:
 *
 * > "A WIP position is a **judgement at a point in time** — the surveyor's forecast, the surveyed percentage, the loss
 * > provision — not a derivation from immutable facts. Recomputing last March's WIP with today's forecast would silently
 * > restate a month that was signed off, reported to a bank and used to compute a bonus, and the journal posted then
 * > would no longer be explicable by any query."
 *
 * So: **the current unlocked period is computed live and the locked ones are frozen.** `locked_at` is the whole
 * mechanism, and it is the reason every judgement input is snapshotted onto the row rather than referenced — the percent
 * complete, the method it came from, the forecast, the contract value. A row that pointed at a forecast run would move
 * when somebody re-forecast, which is the failure this table exists to prevent.
 *
 * **Pending variations are stored and excluded**, both. §4.4 asks for "contract value including **approved** variations
 * with pending ones shown separately and excluded", and the pair is the point: a job whose contract value looks
 * comfortable while eleven million of variations sit unapproved is a job about to be in trouble, and a single figure
 * cannot say so.
 *
 * **The loss is not pro-rated.** §4.4: "when the forecast final cost exceeds the contract value, **the whole expected
 * loss is recognised immediately**". `provision_for_loss` is that figure, and it is stored rather than inferred so a
 * later reading cannot quietly spread it.
 *
 * **And the journal posts the movement.** §4.4 settles the choice and forbids having it twice: "the WIP journal posts the
 * movement from the previous locked snapshot, not the balance… Choose one; two code paths each choosing differently is
 * the failure." `previous_snapshot_id` is what a movement is measured from, and it is a column so the arithmetic is
 * reproducible rather than re-derived by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_wip_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->date('period_start');

            /*
             * **Which method produced `percent_complete`, snapshotted.**
             *
             * §4.4: "cost-to-cost, surveyed, or milestone — chosen per job, because one company runs both." A percentage
             * without its method is a percentage nobody can defend: 62% cost-to-cost on a job whose early works were
             * expensive is not the same claim as 62% surveyed, and a bank asking which will not accept "the system said
             * so".
             */
            $table->enum('percent_complete_method', ['cost_to_cost', 'surveyed', 'milestone']);
            $table->decimal('percent_complete', 7, 4)->default(0)->comment('0–100');

            // The cost side. Actual and accrued kept apart, because §3.5 keeps them apart everywhere else and mixing
            // them makes a WIP position move when nothing happened on site.
            $table->decimal('cost_to_date', 15, 2)->default(0);
            $table->decimal('accrued_to_date', 15, 2)->default(0);

            /*
             * The approved forecast — §4.4's "estimated total cost".
             *
             * Snapshotted rather than joined to `construction_forecast_runs`, which is the whole of `locked_at`'s
             * meaning: a row that pointed at a run would restate itself the moment somebody re-forecast.
             */
            $table->decimal('forecast_final_cost', 15, 2)->default(0);
            $table->foreignId('forecast_run_id')->nullable()
                ->constrained('construction_forecast_runs')->nullOnDelete()
                ->comment('Which run the figure came from, for the audit trail — never re-read for the figure');

            // The revenue side. Original plus approved variations; pending shown and excluded.
            $table->decimal('contract_sum_original', 15, 2)->default(0);
            $table->decimal('variations_approved', 15, 2)->default(0);
            $table->decimal('variations_pending', 15, 2)->default(0)
                ->comment('§4.4: shown separately and excluded from contract value');
            $table->decimal('contract_value', 15, 2)->default(0)->comment('Original plus approved variations');

            $table->decimal('revenue_recognised', 15, 2)->default(0);
            $table->decimal('billings_to_date', 15, 2)->default(0);

            /*
             * **The whole expected loss, immediately.** §4.4, and never pro-rated.
             *
             * Stored rather than inferred from the two figures above it, so no later reading can quietly spread it
             * across the remaining months — which is the single most common way a loss-making contract is reported as
             * profitable until the month it finishes.
             */
            $table->decimal('provision_for_loss', 15, 2)->default(0);

            /*
             * §4.4's two balance-sheet positions, and exactly one of them is non-zero.
             *
             * Two columns rather than one signed figure because they are opposite sides of the balance sheet: a
             * contract asset is money earned and not yet billed, a contract liability is money billed and not yet
             * earned, and a single column would have to be read with a sign convention somebody will get backwards.
             */
            $table->decimal('contract_asset', 15, 2)->default(0)
                ->comment('Costs and recognised profit in excess of billings');
            $table->decimal('contract_liability', 15, 2)->default(0)
                ->comment('Billings in excess of costs and recognised profit');

            /*
             * **`locked_at` is the exception to the house rule, made operational.**
             *
             * Unlocked: computed live, so today's forecast shows in today's position. Locked: frozen, because the month
             * was signed off, reported to a bank and used to compute a bonus.
             */
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();

            // What a movement is measured from. A column rather than "the previous month by date", because a job with a
            // gap in its snapshots would otherwise silently measure against the wrong month.
            $table->foreignId('previous_snapshot_id')->nullable()
                ->constrained('construction_wip_snapshots')->nullOnDelete();

            // The movement journal, when one has been posted. Null until the position is locked and posted.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // One position per job per month. The constraint is the feature, for §3.4's reason about the diary: two WIP
            // positions for one month is how a set of accounts comes to disagree with itself.
            $table->unique(['job_id', 'period_start']);
            $table->index(['period_start', 'locked_at']);
        });

        Schema::table('construction_jobs', function (Blueprint $table) {
            /*
             * **How this job's percent complete is measured** — §4.4, "chosen per job, because one company runs both".
             *
             * Nullable, and the null is a state the report names rather than a default. A company that has not chosen has
             * not decided whether its progress claims are surveyed or inferred from cost, and choosing for them would
             * pick the answer that flatters an over-spending job: cost-to-cost on a job running over reports *more*
             * progress for spending more money, which is exactly backwards.
             */
            $table->enum('percent_complete_method', ['cost_to_cost', 'surveyed', 'milestone'])
                ->nullable()
                ->after('exposure_hours_source')
                ->comment('§4.4: a percentage without its method is one nobody can defend');
        });
    }

    public function down(): void
    {
        Schema::table('construction_jobs', function (Blueprint $table) {
            $table->dropColumn('percent_complete_method');
        });

        Schema::dropIfExists('construction_wip_snapshots');
    }
};
