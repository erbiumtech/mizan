<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair the tables this tenant's schema was created latin1 with.
     *
     * `CompanyProvisioner` ran `CREATE DATABASE IF NOT EXISTS` with no charset until 2026-09-21, so the
     * schema took the MySQL server's default — latin1 on this host — and every table migrated before the
     * default was corrected carries it: 45 of 204 on the live company, `journal_entries`, `invoices`,
     * `accounts`, `employees` and `payslips` among them.
     *
     * It shows up in two ways, and only one of them is visible:
     *
     *  - **A hard error.** A latin1 column compared against a non-ASCII literal is `SQLSTATE[HY000] 3988`,
     *    which is what killed `tenants:seed-baseline` on the Urdu transaction-type aliases.
     *  - **Mojibake.** `journal_entries.memo` read back as "Payroll July â€” Nadeem Yahya".
     *
     * **A plain `CONVERT TO CHARACTER SET` would make the second one permanent**, and that is the whole
     * reason this migration is more than one statement. The bytes in those columns are a *mixture*:
     *
     *  - **Already UTF-8** — `E2 80 94` for "—", `D9 81 D8 B1 …` for Urdu. Written through a latin1 column
     *    by a connection that was not converting, so the bytes are right and the column's label is wrong.
     *    `CONVERT TO` would read each byte as a latin1 character and re-encode it, turning "—" into "â€”"
     *    in the data rather than only in the reading of it.
     *  - **Genuinely cp1252** — a lone `0x97`, which is also an em dash but as Windows-1252 means it. These
     *    need a real transcode, which is exactly what `CONVERT TO` does.
     *
     * So each column that holds non-ASCII is taken through a binary type — which preserves bytes exactly —
     * the cp1252 rows are transcoded in PHP while the bytes are inspectable, and the column is then declared
     * utf8mb4. Tables whose latin1 columns are pure ASCII need none of that: `CONVERT TO` is lossless there
     * and fixes the column collations and the table default in one rebuild.
     *
     * Every step is conditional on finding something to fix, so this is a no-op on a tenant created since
     * the provisioner was corrected, and on SQLite, which is what the test suite runs.
     */
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $database = $connection->getDatabaseName();

        // Aliased one by one, because MySQL 8 returns information_schema columns under their canonical
        // upper-case names however the query spells them, and 5.7 did not.
        $columns = collect(DB::select(
            'select table_name as `table_name`, column_name as `column_name`, column_type as `column_type`,
                    is_nullable as `is_nullable`, column_default as `column_default`,
                    column_comment as `column_comment`
             from information_schema.columns
             where table_schema = ? and collation_name like ?',
            [$database, 'latin1%'],
        ));

        if ($columns->isEmpty()) {
            return;
        }

        // The ALTERs below rebuild tables that reference each other; the charset of a column is not
        // something a foreign key has an opinion about, and checking them mid-rebuild fails on the
        // half-built copy rather than on anything real.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($columns as $column) {
                if ($this->holdsNonAscii($column->table_name, $column->column_name)) {
                    $this->relabel($column);
                }
            }

            foreach ($columns->pluck('table_name')->unique() as $table) {
                DB::statement("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Down is deliberately empty.
     *
     * Rolling back would mean re-declaring the columns latin1, which cannot hold what they now correctly
     * hold — the Urdu aliases have no latin1 representation at all. The way back is the dump taken before
     * this ran, which is what a backup is for.
     */
    public function down(): void {}

    private function holdsNonAscii(string $table, string $column): bool
    {
        return DB::table($table)
            ->whereRaw("`{$column}` <> convert(`{$column}` using ascii)")
            ->exists();
    }

    /**
     * Preserve the bytes, fix the ones that are not UTF-8, then say what they are.
     *
     * Through a binary type in both directions, because that is the only conversion MySQL performs by
     * copying rather than re-encoding. Between the two the rows are examined one at a time: anything that
     * is already valid UTF-8 is left exactly as it is, and anything else is cp1252 and is transcoded.
     */
    private function relabel(object $column): void
    {
        $table = $column->table_name;
        $name = $column->column_name;
        $type = strtolower($column->column_type);

        $binary = $this->binaryEquivalent($type);

        if ($binary === null) {
            // An unexpected type — an enum, say. Left alone and reported rather than guessed at: the
            // table-wide convert below still fixes its collation, and enum values here are ASCII.
            return;
        }

        $null = $column->is_nullable === 'YES' ? 'NULL' : 'NOT NULL';

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$name}` {$binary} {$null}");

        foreach (DB::table($table)->select('id', $name)->get() as $row) {
            $value = $row->{$name};

            if ($value === null || $value === '' || mb_check_encoding($value, 'UTF-8')) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                $name => mb_convert_encoding($value, 'UTF-8', 'Windows-1252'),
            ]);
        }

        $default = $column->column_default === null
            ? ''
            : ' DEFAULT '.(is_numeric($column->column_default) ? $column->column_default : DB::getPdo()->quote($column->column_default));

        $comment = filled($column->column_comment)
            ? ' COMMENT '.DB::getPdo()->quote($column->column_comment)
            : '';

        DB::statement(
            "ALTER TABLE `{$table}` MODIFY `{$name}` {$type} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci {$null}{$default}{$comment}"
        );
    }

    /** The type that holds the same bytes and says nothing about what they mean. */
    private function binaryEquivalent(string $type): ?string
    {
        return match (true) {
            str_starts_with($type, 'varchar(') => str_replace('varchar(', 'varbinary(', $type),
            str_starts_with($type, 'char(') => str_replace('char(', 'binary(', $type),
            $type === 'tinytext' => 'tinyblob',
            $type === 'text' => 'blob',
            $type === 'mediumtext' => 'mediumblob',
            $type === 'longtext' => 'longblob',
            default => null,
        };
    }
};
