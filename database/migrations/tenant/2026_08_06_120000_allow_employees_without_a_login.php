<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an employee exist without a user account.
 *
 * Until now every employee had to be created through Users, because the record
 * was really "an employee is a person who signs in". That holds for staff who
 * use the app, and not at all for a household's driver or cook: they are
 * employed, they get paid, and they will never log in to anything. Forcing a
 * login for them means inventing an email address and a password nobody wants,
 * and leaving a dormant account behind.
 *
 * Everything that maps a user to their employee record already *looks the
 * record up* by user_id rather than assuming one exists — Employee::forUser(),
 * EmployeeAccess::resolveEmployeeIds() — so a null simply never matches, which
 * is the correct behaviour for somebody with no login. The one place that
 * needed changing is EmployeeAccess::accessibleUserIds(), which cast the
 * plucked ids to int and would have turned null into user 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // Where the name lives when there is no user to take it from.
            // Deliberately not a copy for everybody else: for staff who do sign
            // in, the user record stays the one source of truth for their name,
            // and duplicating it here would be a second copy to keep in step.
            $table->string('name')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        // Employees with no login cannot be represented once the column is
        // required again, so they would silently break the schema change.
        // Refuse rather than corrupt.
        $orphans = \Illuminate\Support\Facades\DB::table('employees')->whereNull('user_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot make employees.user_id required again: {$orphans} employee(s) have no login. "
                .'Give them user accounts or delete them first.'
            );
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
