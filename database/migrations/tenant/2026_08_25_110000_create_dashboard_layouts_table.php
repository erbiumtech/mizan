<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one person, or a company, has arranged the dashboard — `docs/reports-expansion-plan.md` Phase 7.
 *
 * "Stored in the `table_views` shape, because that shape has already been argued out: `dashboard_layouts`
 * with `company_id`, `user_id` (null = the company default), a `state` json (order, hidden, spans) and
 * timestamps, company-scoped by a global scope."
 *
 * **The shape, minus the `company_id`, and that difference is not a shortcut.** `table_views` carries a
 * company column because it lives on the *landlord* connection — it has a foreign key to `users`, who live
 * there — so a global scope is the only thing separating one company's rows from another's. This table is on
 * the tenant connection, where the company *is* the database, and Phase 4.5's `saved_report_views` already
 * took that route for the same reason. A `company_id` here would be a column that could only ever hold one
 * value, plus a scope that could only ever be true.
 *
 * **A layout is a partial override, never a list of widgets**, which is item 1 and the decision the rest
 * follows from. The `state` holds an *order* and a *hidden* set and a *spans* map, all keyed on widget alias;
 * resolving means taking the widgets somebody may see and applying those, so a widget the layout has never
 * heard of appears rather than disappearing. A stored array of "the widgets I have" would make every widget
 * added afterwards invisible to whoever saved it — the person who arranged their dashboard becoming the
 * person who never sees a new chart, which is precisely how a layout feature comes to be hated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();

            /*
             * Null is the company default: the arrangement everybody starts from, set by an administrator.
             *
             * Not a foreign key to `users` for the reason `saved_report_views` gives — that table is on the
             * landlord connection and a constraint across connections is not a constraint.
             *
             * **The unique index below does not enforce one company default, and that is a property of SQL
             * rather than an oversight**: both MySQL and SQLite treat NULLs as distinct in a unique index, so
             * two null rows would both be allowed. What enforces it is `DashboardLayout::put()`, which is an
             * `updateOrCreate` on this column — and `whereNull` is what Eloquent generates for a null there,
             * so it does match the existing row rather than inserting beside it. The index still earns its
             * place: the per-user case, which is every row but one, is enforced here.
             */
            $table->unsignedBigInteger('user_id')->nullable()->unique();

            /*
             * The arrangement: `{"order": [...], "hidden": [...], "spans": {...}}`, every key a widget alias.
             *
             * JSON rather than a row per widget per user, because none of it is ever queried *by* widget —
             * the whole state is read at once to render one dashboard and written at once when somebody
             * drops a card. A row per widget would be twenty-three rows to answer one question, and an
             * ordering column to keep consistent across them.
             *
             * Aliases rather than class names, for the reason `TableView::setResourceAttribute()` gives: a
             * widget that moves between directories must not orphan every saved layout.
             */
            $table->json('state');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
