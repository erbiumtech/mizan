<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily site log and its children — `docs/construction-management-plan.md` §16.1.
 *
 * **"Unique on `(job_id, log_date)` — the constraint is the feature, because two site diaries for one day is how a
 * dispute starts."** That is the first line of §16.1 and it is the whole reason the index below is not merely an
 * optimisation: a diary is evidence, and two versions of one day is the state a barrister looks for.
 *
 * **"Approval locks the row. An editable site diary is not evidence."** The second reason this table exists in this
 * shape. A diary anybody can revise after the fact proves nothing about what happened, so approval is a one-way door —
 * with a reopening that records who reopened it and why, because a locked diary that is *wrong* would otherwise stay
 * wrong for ever, and a company would keep its real diary somewhere else.
 *
 * **`working_conditions` and `weather_hours_lost` are the claim, not the prose.** §16.1: "those last two, not the free
 * text, are what a weather-based extension of time is actually made of". A paragraph saying "rained all afternoon,
 * lost most of the pour" is unquantifiable; four hours against a stopped condition is a number an assessor can work
 * with, and §13's delay event can point at it.
 *
 * The children carry the value:
 *
 *  - **Manpower** by trade, with headcount, hours and overtime — feeding dayworks pricing, the manpower histogram, and
 *    §17's exposure-hours denominator, which is the figure a safety rate is meaningless without.
 *  - **Plant**, with **working, idle and breakdown hours kept apart**, because "idle against working is what a
 *    standing-time claim is made of and one combined hours column loses it entirely".
 *  - **Events**, each with times, hours lost, a responsibility of employer, contractor or neutral, and a nullable link
 *    to §13's delay event — which is what turns a diary line into a notified claim.
 *
 * Deliveries and photos are the two remaining children and are deliberately not here: the delivery's
 * `is_materials_on_site` flag is what §16.1 calls "the link that makes G703's materials presently stored column
 * defensible", and Phase 8c already computes that figure from stock. Two sources for one number needs a decision rather
 * than a table, so it gets its own sub-phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_daily_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_id')->constrained('construction_jobs')->cascadeOnDelete();
            $table->date('log_date');

            // Morning and afternoon separately, because a day that was workable until two o'clock is the commonest
            // shape of a weather claim and one summary word loses it.
            $table->string('weather_am')->nullable();
            $table->string('weather_pm')->nullable();
            $table->decimal('temperature_c', 5, 1)->nullable();
            $table->decimal('rainfall_mm', 6, 1)->nullable();
            $table->decimal('wind_kph', 6, 1)->nullable();

            /*
             * **The two columns a weather-based extension of time is actually made of** (§16.1).
             *
             * `workable` is the default because most days are, and a default of `stopped` would make an unfilled diary
             * read as a claim.
             */
            $table->enum('working_conditions', ['workable', 'partially_disrupted', 'stopped'])->default('workable');
            $table->decimal('weather_hours_lost', 6, 2)->default(0);

            $table->text('work_summary')->nullable();
            $table->text('delays')->nullable();
            $table->text('instructions_received')->nullable();
            $table->text('visitors')->nullable();
            $table->text('safety_observations')->nullable();
            $table->text('quality_observations')->nullable();
            $table->text('environmental_observations')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

            /*
             * **Approval locks the row**, which is the point. Recorded rather than implied by a status alone, so "who
             * signed this day off" is answerable — the question asked first when a diary is produced in a dispute.
             */
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Reopening a signed day is a deliberate act with an author and a reason. A locked diary that is wrong
            // would otherwise stay wrong for ever, and the company would keep its real diary somewhere else.
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // **The constraint is the feature** (§16.1): two diaries for one day is how a dispute starts.
            $table->unique(['job_id', 'log_date']);
            $table->index(['job_id', 'log_date', 'working_conditions']);
            $table->index('approved_at');
        });

        Schema::create('construction_daily_log_manpower', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_log_id')->constrained('construction_daily_logs')->cascadeOnDelete();

            /*
             * The trade, **unconstrained on purpose**.
             *
             * `construction_trades` belongs to `construction_costing`, and `construction_field` requires only
             * `construction` (§18) — so this module reads an integer and never names the `Trade` class, the same
             * treatment §13's `contract_id` gets. `trade_label` is the fallback: a diary written on a job with no cost
             * module still has to say what the men were doing, and a picker that cannot be offered must not stop the
             * day being recorded.
             */
            $table->unsignedBigInteger('trade_id')->nullable();
            $table->string('trade_label')->nullable();

            // Who supplied them — a subcontractor is a Contact, which Invoicing owns, so the column stays null without
            // that module and the free-text company name carries it.
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('company_label')->nullable();

            $table->unsignedInteger('headcount')->default(0);
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('daily_log_id');
        });

        Schema::create('construction_daily_log_plant', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_log_id')->constrained('construction_daily_logs')->cascadeOnDelete();

            // Same treatment as the trade above, and for the same licensing reason: `construction_plant_items` is
            // `construction_costing`'s.
            $table->unsignedBigInteger('plant_item_id')->nullable();
            $table->string('plant_label')->nullable();

            /*
             * **Working, idle and breakdown kept apart** (§16.1): "idle against working is what a standing-time claim is
             * made of and one combined hours column loses it entirely". Breakdown is separate again because it is the
             * contractor's own risk where idle usually is not, and a claim that mixes them invites the whole thing to
             * be rejected.
             */
            $table->decimal('working_hours', 8, 2)->default(0);
            $table->decimal('idle_hours', 8, 2)->default(0);
            $table->decimal('breakdown_hours', 8, 2)->default(0);

            $table->string('idle_reason')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('daily_log_id');
        });

        Schema::create('construction_daily_log_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_log_id')->constrained('construction_daily_logs')->cascadeOnDelete();

            $table->enum('kind', ['delay', 'instruction', 'visitor', 'stoppage', 'inspection', 'incident']);

            $table->time('started_at')->nullable();
            $table->time('ended_at')->nullable();
            $table->decimal('hours_lost', 6, 2)->default(0);

            /*
             * **Whose risk it was**, recorded on the day rather than argued about later. The diary is the only document
             * written while anybody still remembers, and an event with no responsibility against it is the one that
             * gets assigned to the contractor by default six months on.
             */
            $table->enum('responsibility', ['employer', 'contractor', 'neutral'])->default('neutral');

            /*
             * The delay event of §13 this diary line belongs to, where somebody has raised one.
             *
             * Nullable, and the null is the useful state: it is a diary line that has cost time and that **nobody has
             * served notice for**, which is exactly the silent loss §13's clock exists to prevent. A screen can now ask
             * that question of the diary rather than of somebody's memory.
             */
            $table->foreignId('delay_event_id')->nullable()
                ->constrained('construction_delay_events')->nullOnDelete();

            $table->text('description');
            $table->string('raised_with')->nullable()->comment('Who on the other side was told, on the day');
            $table->timestamps();

            $table->index('daily_log_id');
            $table->index(['kind', 'responsibility']);
            // The exposure query: diary events that cost time and have no delay event behind them.
            $table->index('delay_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_daily_log_events');
        Schema::dropIfExists('construction_daily_log_plant');
        Schema::dropIfExists('construction_daily_log_manpower');
        Schema::dropIfExists('construction_daily_logs');
    }
};
