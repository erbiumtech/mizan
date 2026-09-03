<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `employees.gender` becomes a string — the same bug, and the same fix, as
 * `2026_08_28_120000_widen_invoice_kind_for_notes`.
 *
 * The column was created as `enum('Male', 'Female')` in June. The employee form has offered a third option,
 * "Other", ever since — and widened nothing. On SQLite, which is what the test suite and every SQLite
 * installation run, Laravel renders `enum` as a plain `varchar` with no check constraint, so "Other" stores
 * happily and no test ever noticed. `.env.example` documents `TENANT_DB_DRIVER=mysql` for production, where
 * MySQL's ENUM is real: strict mode rejects the insert, non-strict mode silently stores the empty string. So
 * on the configuration production runs, an employee who is neither Male nor Female could not be recorded —
 * the save threw, or the gender vanished into an empty string that every `where('gender', ...)` omits.
 *
 * **A string rather than a wider enum**, for the reason the invoice migration gives and one more of its own:
 * gender is now a list this company writes (`employees.gender`, declared in app/Modules/Employees/module.php
 * and edited under Settings → Dropdown Options), so the set of accepted values is not knowable from here at
 * all. An enum would have to be rebuilt every time somebody added an entry, which is exactly what the
 * dropdown exists to avoid.
 *
 * Nothing in `app/` compares this column to a literal: it is recorded, printed on the employee PDF and
 * reported on. Widening it changes no behaviour beyond making the third answer storable.
 *
 * **Run on every driver**, though only MySQL has anything to change. On SQLite this rebuilds a table whose
 * column is already a varchar — no gain, no loss, and exercised on every test run rather than only in
 * production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // 20 rather than the default 255. Long enough for the answers a person gives; short enough that
            // the column stays cheap in a table every payroll and attendance query joins.
            $table->string('gender', 20)->nullable()->change();
        });
    }

    /**
     * Deliberately not reversible.
     *
     * Down would narrow the column back to two values, which means either refusing to run while anybody is
     * recorded as anything else, or overwriting how those people answered. Neither is a rollback worth
     * offering for a column whose whole problem was that it only allowed two answers.
     */
    public function down(): void
    {
        //
    }
};
