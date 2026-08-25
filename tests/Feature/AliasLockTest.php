<?php

namespace Tests\Feature;

use App\Support\ModuleMap;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * An alias, once shipped, never changes.
 *
 * `ModuleMap::alias()` produces the string this application writes into
 * `journal_entries.source_type`, `stock_movements.source_type`,
 * `payments.payable_type`, `custom_fields.model_type`, `consents.subject_type`
 * and `table_views.resource`. Those rows are in every tenant database and there
 * is no central place to rewrite them, which is the entire reason the indirection
 * exists: the class may move, the token may not.
 *
 * So the alias is not a naming convention — it is a **storage format**, and this
 * test treats it as one. The lock file is the shipped format; a diff to it is a
 * change to data already written to customers' databases and has to be argued for
 * in review rather than noticed afterwards.
 *
 * This complements, rather than replaces, ModuleCoverageTest's assertion that
 * aliases take the legacy `App\Models\X` form. That rule governs what a *new*
 * alias may look like; this one governs whether an *existing* one may move. When
 * modules become packages the first rule has to go — two packages cannot both
 * mint `App\Models\Payment` — and this one becomes the only thing standing
 * between a refactor and a silently unreadable column.
 */
class AliasLockTest extends TestCase
{
    private const LOCK = 'tests/alias-lock.json';

    public function test_no_shipped_alias_has_changed(): void
    {
        $locked = $this->lock();
        $current = $this->current();

        $changed = [];
        $missing = [];

        foreach ($locked as $kind => $entries) {
            foreach ($entries as $alias => $class) {
                // The class behind an alias may legitimately move — that is what
                // the indirection is for. The alias disappearing is the failure.
                if (! array_key_exists($alias, $current[$kind] ?? [])) {
                    $missing[] = sprintf('%s: %s (was %s)', $kind, $alias, $class);
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These aliases have been shipped and are stored in tenant databases, and',
            'nothing produces them any more. Every row holding one is now unreadable:',
            'a morph read cannot resolve the class, and a plain column read matches',
            'nothing and reports no error.',
            '',
            'If the class moved, keep the alias and update '.self::LOCK.' to name the',
            'new class. If the class is genuinely gone, that is a data migration.',
            '',
            ...$missing,
        ]));

        // The other direction: a class that gained a *different* alias than the one
        // it shipped with. Same harm, opposite symptom — old rows keep the old
        // token while new rows get the new one, and neither can see the other.
        foreach ($current as $kind => $entries) {
            foreach ($entries as $alias => $class) {
                $previous = array_search($class, $locked[$kind] ?? [], true);

                if ($previous !== false && $previous !== $alias) {
                    $changed[] = sprintf('%s: %s was stored as %s, now %s', $kind, $class, $previous, $alias);
                }
            }
        }

        $this->assertSame([], $changed, implode("\n", [
            'These classes shipped under one alias and now produce another. Rows',
            'written before the change still hold the old token and will not be found.',
            '',
            ...$changed,
        ]));
    }

    public function test_every_alias_is_recorded_in_the_lock(): void
    {
        $locked = $this->lock();
        $unrecorded = [];

        foreach ($this->current() as $kind => $entries) {
            foreach ($entries as $alias => $class) {
                if (! array_key_exists($alias, $locked[$kind] ?? [])) {
                    $unrecorded[] = sprintf('%s: %s => %s', $kind, $alias, $class);
                }
            }
        }

        $this->assertSame([], $unrecorded, implode("\n", [
            'New aliases are fine; unrecorded ones are not. Add these to '.self::LOCK,
            'so that the next change to them is visible in a diff.',
            '',
            ...$unrecorded,
        ]));
    }

    /**
     * The one place an alias is written as a literal still matches the locked one.
     *
     * `BackfillPaymentEntriesCommand` filters `source_type` on the payslip alias, and uses the string
     * rather than `ModuleMap::alias(Payslip::class)` so that Accounting need not import a Payroll model to
     * name a column value (docs/module-packaging-plan.md §8). That is only safe while the two agree, and
     * this is what makes it safe.
     */
    public function test_the_hardcoded_payslip_source_alias_matches_the_lock(): void
    {
        $constant = (new \ReflectionClass(\App\Modules\Accounting\Console\Commands\BackfillPaymentEntriesCommand::class))
            ->getConstant('PAYSLIP_SOURCE');

        $this->assertSame(
            ModuleMap::alias(\App\Modules\Payroll\Models\Payslip::class),
            $constant,
            'the backfill command filters on an alias that is no longer what Payslip produces',
        );
    }

    public function test_the_lock_is_not_empty(): void
    {
        // The failure this whole file exists to prevent is a silent one, and a lock
        // that reads as empty would assert nothing while passing. That has happened
        // in this repository before: docs/modules-plan.md §13 records the coverage
        // test scanning two directories that were empty after a move and passing
        // every invariant over nothing.
        $counts = array_map('count', $this->lock());

        $this->assertGreaterThan(100, $counts['models'] ?? 0);
        $this->assertGreaterThan(50, $counts['resources'] ?? 0);
        $this->assertNotEmpty($counts['pages'] ?? []);
        $this->assertNotEmpty($counts['widgets'] ?? []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function lock(): array
    {
        $path = base_path(self::LOCK);

        $this->assertTrue(File::exists($path), self::LOCK.' is missing.');

        return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function current(): array
    {
        $current = [];

        foreach (['models', 'resources', 'pages', 'widgets', 'datasets'] as $kind) {
            foreach (ModuleMap::$kind() as $class) {
                $current[$kind][ModuleMap::alias($class)] = $class;
            }

            ksort($current[$kind]);
        }

        return $current;
    }
}
