<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trades, the people who work them, and what an hour of each costs — `docs/construction-management-plan.md` §7.
 *
 * **Construction owns its labour records, and §7.1 says the reason is not tidiness.** Three facts force it.
 * `timesheet_entries` bills and never costs — its own migration comment says the rate ladder resolves what time is
 * *billed* at, and there is no cost rate and no burden anywhere in it. `timesheet_entries.project_id` is **not null**
 * and constrained to `projects`, so a construction job cannot appear on one at all without that module. And the
 * decisive one: **on a site, most hands are not employees** — no payslip, no login, paid weekly through a gang
 * leader. Requiring an `Employee` row per labourer would make Employees a hard dependency of this module and would
 * put three hundred people who are not employed into the HR register, where Leave, Payroll and Lifecycle would then
 * all see them.
 *
 * So `employee_id` here is **nullable and unconstrained**, exactly as `construction_cost_entries.employee_id` is, and
 * `subcontractor_contact_id` carries the gang leader who supplies the labour.
 *
 * **`construction_labour_rates` is a dated table and not a rate column, and this is the load-bearing decision of §7.**
 * A wage revision effective the first of April must not restate March's job cost. A `cost_rate_per_hour` column on the
 * worker does exactly that, silently, the moment somebody edits it: every historical record recomputes, every closed
 * period's cost changes, and there is no journal, no audit and no report of what moved. The dated table is the first
 * line of defence and the snapshot on the labour record is the second — §7.1 puts `cost_rate_per_hour` and
 * `burden_percent` on the record itself, frozen at approval.
 *
 * Which is why **no rate column appears on `construction_trades` or `construction_workers`**. §7.1's phrase is
 * "`construction_trades` with default codes and rates", and the default *code* is here as a column while the default
 * *rate* is a row in the dated table with `trade_id` set and everything else null — the "company default" tier of
 * §7.2's ladder. A rate column on the trade would be the same silent restatement one table along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_trades', function (Blueprint $table) {
            $table->id();

            // Carpenter, steel fixer, mason, plant operator. The code is what appears on a site sheet, so it is
            // short and the company chooses it — §18.2 ships structure rather than seeded code lists.
            $table->string('code', 32);
            $table->string('name');
            $table->string('description')->nullable();

            /*
             * The cost code labour of this trade normally books to, so a site sheet does not ask for it twice.
             *
             * A default rather than a rule: one trade works to several codes on a job of any size, and the labour
             * record carries its own `cost_code_id`, which is the authority. Nullable, because a company may code
             * labour by activity rather than by trade.
             */
            $table->foreignId('default_cost_code_id')->nullable()
                ->constrained('construction_cost_codes')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });

        Schema::create('construction_workers', function (Blueprint $table) {
            $table->id();

            // The number the gang leader and the site sheet use. Unique, because two people with one number is how
            // a week's hours land on the wrong person.
            $table->string('code', 32);
            $table->string('name');

            /*
             * **How this person is engaged, which is the whole reason this table exists** (§7.1).
             *
             *   employee   — on the payroll, with an `employee_id` beside it
             *   direct     — paid directly by this company and not on the payroll: daily-wage, weekly, casual
             *   supplied   — supplied through a gang leader or agency, who is the one that gets paid
             *
             * `direct` is the default because it is the commonest case on a site and the one the HR register cannot
             * hold. Making `employee` the default would invite an `employee_id` on rows that have none.
             */
            $table->enum('engagement', ['employee', 'direct', 'supplied'])->default('direct');

            /*
             * Nullable and **unconstrained**, like `construction_cost_entries.employee_id`: `employees` is guarded
             * rather than required (§18.1), so a company running construction without the HR module still records
             * every hour worked, and `created_by` answers ownership — exactly the `crm -> employees` shape.
             */
            $table->unsignedBigInteger('employee_id')->nullable();

            // Who supplies this person, where somebody else does. The gang leader is a supplier and a supplier is a
            // Contact, so the column follows Invoicing's table and is null without it.
            $table->foreignId('subcontractor_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            $table->foreignId('trade_id')->nullable()->constrained('construction_trades')->nullOnDelete();

            $table->string('national_id', 64)->nullable();
            $table->string('phone', 32)->nullable();

            // On and off the books of this site. Dates rather than a flag alone, because "was he on site in March"
            // is asked at exactly the moment somebody disputes a week's hours.
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->index(['engagement', 'is_active']);
            $table->index('trade_id');
            $table->index('employee_id');
        });

        Schema::create('construction_labour_rates', function (Blueprint $table) {
            $table->id();

            /*
             * The four scope columns, **all nullable**, and which of them are set is what makes a row more or less
             * specific. §7.2's ladder resolves `job+trade -> job -> worker/employee -> trade -> company default`,
             * and a row with every column null is that last tier.
             *
             * A row is a *candidate* for a question only if none of its set columns contradicts it, so a rate for job
             * 7 can never be picked for job 9. The ordering between candidates is the ladder, and it lives in
             * `LabourRateService` rather than in an index — the same judgement Phase 3 made for one measurement per
             * control account per period, and for the same reason: nulls in a unique index are distinct in both MySQL
             * and SQLite, so the index would let the row through and something downstream would silently double.
             */
            $table->foreignId('job_id')->nullable()->constrained('construction_jobs')->cascadeOnDelete();
            $table->foreignId('trade_id')->nullable()->constrained('construction_trades')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('construction_workers')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable();

            // Per hour, at four decimal places, because a rate is divided by sixty on the way to a minute and two
            // places would lose the difference on a month of a hundred men.
            $table->decimal('cost_rate_per_hour', 14, 4);

            /*
             * What an overtime hour costs relative to a normal one, and the burden on top of both.
             *
             * Both nullable so a row can revise the rate without restating the multiplier a company set once — a
             * null falls through to the next tier of the ladder for that field alone, which is what lets a job carry
             * a site allowance on the rate while inheriting the company's overtime terms.
             */
            $table->decimal('overtime_multiplier', 6, 4)->nullable();
            $table->decimal('burden_percent', 8, 4)->nullable();

            // The whole point of the table. `effective_to` null means "until further notice", which is the ordinary
            // state of the current rate.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // The resolution query: candidates for a date, narrowed by scope.
            $table->index(['effective_from', 'effective_to']);
            $table->index(['job_id', 'trade_id']);
            $table->index('worker_id');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_labour_rates');
        Schema::dropIfExists('construction_workers');
        Schema::dropIfExists('construction_trades');
    }
};
