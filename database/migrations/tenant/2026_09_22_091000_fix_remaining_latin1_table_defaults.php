<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables the repair before this one could not reach: a latin1 *default* and no latin1 column.
     *
     * `2026_09_22_090000` works from the columns that actually hold text, because those are the ones whose
     * bytes needed care, and converts each table it found one of in. A table with no text column at all —
     * `annual_taxes`, `employee_settings`, `salary_slabs`, `withholding_deductions`, all figures and foreign
     * keys — therefore never came up, and neither did `dashboard_layouts`, whose one text column was added
     * after the schema default had been corrected and is already utf8mb4.
     *
     * Nothing is wrong with their data; there is none to be wrong. What is wrong is the default they hand
     * to the *next* column somebody adds, which is precisely how five of these became forty-five. So this
     * converts what is left, which for a table with no text column is a metadata change.
     *
     * Separate from the first migration rather than folded into it because that one has already run: a
     * repair that has been applied is a fact about the database, and editing it afterwards means the code
     * and the `migrations` table disagree about what happened.
     */
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $tables = DB::select(
            'select table_name as `table_name` from information_schema.tables
             where table_schema = ? and table_collation like ?',
            [$connection->getDatabaseName(), 'latin1%'],
        );

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table->table_name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    /** Empty for the reason the first one's is: there is no going back to a charset that holds less. */
    public function down(): void {}
};
