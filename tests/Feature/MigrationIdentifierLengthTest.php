<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * No migration may ask for an identifier MySQL will not accept.
 *
 * **This exists because the whole suite passed and the deploy did not.** The tests run on SQLite,
 * which has no limit on identifier length; production is MySQL, which caps one at 64 characters and
 * refuses anything longer:
 *
 *     SQLSTATE[42000]: 1059 Identifier name '...' is too long
 *
 * On 2026-11-01 that stopped a deploy half way through the first tenant — after the landlord
 * database had migrated and the new code was live — leaving the application serving a schema
 * thirty-four migrations behind. Four further migrations carried the same fault and would have
 * broken the next four attempts one at a time.
 *
 * Laravel names an index `{table}_{column}_{column}_{type}` when you do not name it yourself, so
 * the fault is invisible at the call site: nothing in `$table->unique(['a', 'b'])` looks sixty-six
 * characters long. This computes the name Laravel would generate and measures it.
 *
 * **The fix is always to name the index**, never to shorten a column:
 *
 *     $table->unique(['salary_structure_id', 'pay_component_id'], 'unique_structure_component');
 */
class MigrationIdentifierLengthTest extends TestCase
{
    /** What MySQL allows. Postgres allows 63, but this application targets MySQL. */
    private const LIMIT = 64;

    /**
     * Every implicit index name in every migration, measured.
     *
     * Parsed from the source rather than by running the migrations and reading the schema: a
     * migration that cannot run on SQLite is not in the suite at all, and the point is to catch the
     * name before anybody tries it against MySQL.
     */
    public function test_no_migration_generates_an_identifier_mysql_would_refuse(): void
    {
        $tooLong = [];

        foreach ($this->migrations() as $path) {
            foreach ($this->implicitIndexNames(File::get($path)) as $name) {
                if (strlen($name) > self::LIMIT) {
                    $tooLong[] = sprintf('%s (%d chars) in %s', $name, strlen($name), basename($path));
                }
            }
        }

        $this->assertSame([], $tooLong, implode("\n", [
            'These migrations ask for an index name longer than MySQL allows (64 characters).',
            'SQLite accepts them, so the suite passes and the deploy fails half way through a',
            'tenant — see this test\'s docblock for what that costs.',
            '',
            'Name the index explicitly, with something short and descriptive:',
            '',
            "    \$table->unique(['a_id', 'b_id'], 'unique_a_b');",
            '',
            ...$tooLong,
        ]));
    }

    /** The scan has to actually find names, or an empty result would pass for the wrong reason. */
    public function test_the_scan_finds_the_names_it_is_measuring(): void
    {
        $found = [];

        foreach ($this->migrations() as $path) {
            $found = [...$found, ...$this->implicitIndexNames(File::get($path))];
        }

        $this->assertGreaterThan(50, count($found), 'the parser stopped matching the migrations');
        $this->assertContains('work_items_project_id_rank_index', $found);
    }

    /** @return array<int, string> */
    private function migrations(): array
    {
        return array_map(
            fn ($file): string => $file->getPathname(),
            File::allFiles(database_path('migrations')),
        );
    }

    /**
     * The names Laravel would generate for the unnamed indexes in one migration.
     *
     * An index given a name of its own is skipped — that is the fix, and a long explicit name would
     * be somebody's deliberate choice rather than an accident of concatenation.
     *
     * @return array<int, string>
     */
    private function implicitIndexNames(string $source): array
    {
        $names = [];

        // Each Schema::create()/table() block, so the index name can be prefixed with its table.
        preg_match_all(
            '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]/i',
            $source,
            $tables,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($tables[1] as $i => [$table, $offset]) {
            $start = $offset;
            $end = $tables[1][$i + 1][1] ?? strlen($source);
            $body = substr($source, $start, $end - $start);

            // Multi-column: ->unique(['a', 'b']) with no second argument.
            preg_match_all(
                '/\$table->(unique|index|fullText|spatialIndex)\(\s*\[([^\]]*)\]\s*(,|\))/',
                $body,
                $multi,
                PREG_SET_ORDER,
            );

            foreach ($multi as $match) {
                if (trim($match[3]) === ',') {
                    continue; // named explicitly
                }

                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/i', $match[2], $columns);

                $names[] = $table.'_'.implode('_', $columns[1]).'_'.$this->suffix($match[1]);
            }

            // Single column, chained: ->string('code')->unique()
            preg_match_all(
                '/\$table->\w+\(\s*[\'"]([a-z0-9_]+)[\'"][^;]*?->(unique|index)\(\s*\)/',
                $body,
                $single,
                PREG_SET_ORDER,
            );

            foreach ($single as $match) {
                $names[] = $table.'_'.$match[1].'_'.$this->suffix($match[2]);
            }
        }

        return $names;
    }

    private function suffix(string $kind): string
    {
        return match ($kind) {
            'unique' => 'unique',
            'fullText' => 'fulltext',
            'spatialIndex' => 'spatialindex',
            default => 'index',
        };
    }
}
