<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report somebody assembled — `docs/reports-expansion-plan.md` Phase 6, item 3.
 *
 * "**Definitions stored like saved views**, deliberately: `report_definitions` with `company_id`, `user_id`,
 * `name`, `description`, `dataset`, a `state` json (columns, filters, group by, aggregates, sort, period),
 * `is_public`, `is_global`, `is_default`, `icon`, `color` — the same shape as `table_views`."
 *
 * The shape, minus four of those columns. Each omission is a decision rather than an oversight:
 *
 * **No `company_id`.** `table_views` needs one because it lives on the *landlord* connection — it has a
 * foreign key to `users`, who live there — so a global scope is the only thing separating one company's rows
 * from another's. This table is on the tenant connection, where the company *is* the database, exactly as
 * Phase 4.5's `saved_report_views` and Phase 7's `dashboard_layouts` already argued. A column that can only
 * hold one value plus a scope that can only be true is not the same shape, it is the same shape's shadow.
 *
 * **No `is_global`, because `table_views` has never used it.** The plan points at that table as the working
 * example, and in it `is_global` is in `$fillable`, in the visibility scope, in the policy and in the factory
 * as `false` — and *nothing in the application ever sets it true*. What is actually used is `is_public`, "make
 * this view available to everyone in this company". So there is one sharing flag here, which is also the one
 * item 6 needs a permission for.
 *
 * **No `is_default`.** On a table view it means "the view this resource's table opens with", which is a real
 * thing because a resource has one table. A report is opened by name from a list, so there is nothing for a
 * default to be. A column nothing sets is how a schema accretes.
 *
 * **No `icon` or `color`, and this one is a measurement.** Phase 4 took the reports hub from 366 KB to 348.5 KB
 * by replacing 51 inline heroicons with nine `<symbol>`s and 51 `<use>`s — 30.4 KB down to 6.2 KB — precisely
 * because the icons repeat per *section*. A per-definition icon reintroduces the distinct-icon-per-row shape
 * that saving deleted, on the one page `PanelPerformanceTest` says must not grow. Custom reports get the
 * Custom section's icon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();

            /*
             * Who built it. Not a foreign key: `users` is on the landlord connection and a constraint across
             * connections is not one — the same note `saved_report_views` carries.
             *
             * Never null, unlike `dashboard_layouts.user_id`. There is no such thing as "the company's
             * definition with no author": a shared report is somebody's report that they shared, and knowing
             * whose matters when it turns out to be wrong.
             */
            $table->unsignedBigInteger('user_id')->index();

            $table->string('name');
            $table->string('description')->nullable();

            /*
             * Which subject it reports on, as `ModuleMap::alias()` — Phase 6.1's `Dataset::key()`.
             *
             * The class may move between directories; this token may not, for the reason
             * `TableView::setResourceAttribute()` gives about not orphaning saved rows. Not a foreign key to
             * anything: a dataset is code.
             */
            $table->string('dataset');

            /*
             * What the report asks for: `{"columns": [...], "filters": {...}, "group_by": ..., "aggregates":
             * {...}, "sort": {...}, "period": "last_month"}`, every key a *declared* key of that dataset.
             *
             * JSON rather than a table of rows per definition, for the reason `dashboard_layouts` gives: the
             * whole state is read at once to render one report and written at once when somebody saves. A row
             * per column would be an ordering column to keep consistent and nothing gained.
             *
             * **The period is relative and never two dates** — see `RelativePeriod`. A definition holding
             * "1 April to 30 June" is a report that answers last quarter's question for ever, which is the
             * trap Phase 4.5 avoided by keeping the date out of a saved view altogether. Phase 8 will send
             * these on a schedule, where a fixed span would be actively wrong.
             */
            $table->json('state');

            /*
             * Shared with everyone in this company.
             *
             * Item 6's sharp end — "a company-wide custom report over payslips is a payroll leak, and it is
             * one careless toggle away". Two things answer that and neither is this column: setting it needs
             * `ReportShare`, and *reading* a definition resolves its dataset through the reader's own module
             * and permission gates, so a shared payslip report shows nothing to somebody who may not see
             * payslips. The toggle cannot leak what the reader could not already open.
             */
            $table->boolean('is_public')->default(false);

            $table->timestamps();

            // Saving over a name replaces it, which is what somebody adjusting last month's report means by
            // pressing save again. Per user, so two people may each have "Sales by month".
            $table->unique(['user_id', 'name'], 'report_definitions_unique_name');

            // Every read is either "mine" or "mine and the shared ones", and both start here.
            $table->index(['is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
