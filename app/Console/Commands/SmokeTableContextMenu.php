<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Proves the right-click menu in a real browser — `docs/table-context-menu-plan.md` §5.3.
 *
 * The menu is built in JavaScript from the DOM, so `TableContextMenuTest` can pin the *rule* it follows
 * and nothing more. This is the other half: a headless Chrome actually right-clicks a row.
 *
 * **It renders a real table and hands the output to the browser**, rather than letting the browser talk
 * to a running application. Two things fall out of that, and both are the reason it is built this way:
 *
 *  - No server, no session, no database access from the browser — so this runs anywhere, including a CI
 *    box with nothing but PHP and node.
 *  - The markup is **Filament's**, not a fixture somebody wrote once. A Filament upgrade that renames a
 *    class fails this command instead of silently disabling the feature. That is not a hypothetical:
 *    Phase 1 shipped a selector for `.fi-ta-record` which matched nothing, because the ordinary table
 *    layout renders `.fi-ta-row` — two branches of one Blade file, a thousand lines apart, and reading
 *    it was not enough to tell.
 *
 * Not a PHPUnit test on purpose: it needs node and a Chrome download, which is a dependency the test
 * suite should not acquire in order to assert something about a stylesheet and a gesture.
 */
class SmokeTableContextMenu extends Command
{
    protected $signature = 'table-context-menu:smoke
                            {--keep : Leave the generated harness on disk for inspection}';

    protected $description = 'Right-click a real rendered table row in headless Chrome and check the menu behaves';

    public function handle(): int
    {
        $harness = storage_path('app/table-context-menu-harness.html');

        File::ensureDirectoryExists(dirname($harness));
        File::delete($harness);

        if (! $this->renderATable($harness)) {
            return self::FAILURE;
        }

        // The test wrote raw table markup; wrap it in the page the browser opens.
        File::put($harness, $this->harness(File::get($harness)));

        $this->line('Harness: '.$harness);

        $process = new Process(
            ['node', 'scripts/table-context-menu-smoke.cjs', $harness],
            base_path(),
            timeout: 180,
        );

        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $this->option('keep')) {
            File::delete($harness);
        }

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A real table, rendered — through PHPUnit, which is the part worth explaining.
     *
     * `Livewire::test()` needs the testing harness bootstrapped; called from a console command it throws
     * *"Invalid Livewire snapshot structure"*. Rather than reimplement Livewire's render pipeline by hand
     * — fragile, and wrong the first time Livewire changes it — this shells out to the one test method
     * that already does it correctly, on a fresh in-memory database, and takes the file it writes.
     *
     * The invoices list is the subject because its actions are genuinely per-record: each `visible()`
     * closure is a permission *and* a record state, so a draft row and an issued row produce different
     * menus. A table whose every row offered the same actions would let every browser assertion pass
     * while proving nothing about the one property this feature is for.
     */
    private function renderATable(string $harness): bool
    {
        $phpunit = new Process(
            [
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--filter=test_it_writes_the_context_menu_harness',
                'tests/Feature/TableContextMenuTest.php',
            ],
            base_path(),
            /*
             * The test environment, restated.
             *
             * Symfony's Process inherits this command's environment, and by the time artisan is running
             * Laravel has loaded `.env` into it — so `DB_CONNECTION=mysql` reaches the child and
             * overrides what `phpunit.xml` declares. The subprocess then tries to seed the developer's
             * real database and fails in `FiscalYearSeeder`, which is how this was discovered. The three
             * that matter are named here rather than the whole file: everything else in `phpunit.xml`
             * has no inherited counterpart to lose to.
             */
            [
                'CONTEXT_MENU_HARNESS' => $harness,
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'TENANT_DB_DRIVER' => 'sqlite',
            ],
            timeout: 180,
        );

        $phpunit->run();

        if (! File::exists($harness)) {
            $this->error('The harness was not written. PHPUnit said:');
            $this->line(trim($phpunit->getOutput()."\n".$phpunit->getErrorOutput()));

            return false;
        }

        return true;
    }

    /**
     * The page the browser opens: the rendered table, the real script, and the menu's own styles.
     *
     * The styles are inlined rather than pulled from the built theme because the assertions are about
     * behaviour, and all they need of CSS is that `[hidden]` hides — a menu the browser considers visible
     * when it should be closed would make `escapeCloses` meaningless. The real appearance is
     * `resources/css/filament/admin/theme.css`, which the Vite build compiles and which no headless
     * assertion has an opinion about.
     */
    private function harness(string $markup): string
    {
        $script = File::get(resource_path('js/table-context-menu.js'));
        $alpine = $this->alpineStub();

        return <<<HTML
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Table context menu harness</title>
            <style>
                .fi-ta-context-menu { position: fixed; z-index: 51; min-width: 11rem; background: #fff; border: 1px solid #ccc; }
                .fi-ta-context-menu[hidden] { display: none; }
                .fi-ta-context-menu-item { display: flex; width: 100%; padding: 6px 8px; }
            </style>
        </head>
        <body>
        {$markup}
        <script>
        {$alpine}
        </script>
        <script>
        {$script}
        </script>
        </body>
        </html>
        HTML;
    }

    /**
     * Just enough of Filament's `filamentTable` Alpine component to exercise §1.4's selection branch.
     *
     * **A stub, and it is the one compromise in this command.** The real component needs Alpine, Livewire
     * and a server; this harness is a `file://` page by design, so the selection state is faked. What is
     * being tested is therefore *the script's branching* — selection wins, outside clears, the count
     * reaches the label — and not Filament's own selection tracking.
     *
     * The contract that stub stands in for is pinned separately and for real:
     * `TableContextMenuTest::test_filaments_selection_api_still_carries_the_members_we_call` reads
     * Filament's shipped bundle and fails if `isRecordSelected`, `getSelectedRecordsCount` or
     * `deselectAllRecords` is renamed. Between the two, a rename breaks a test rather than the feature.
     *
     * `window.__selection` is the handle the browser script uses to arrange a selection.
     */
    private function alpineStub(): string
    {
        return <<<'JS'
        window.__selection = new Set();

        window.Alpine = {
            $data: () => ({
                isRecordSelected: (key) => window.__selection.has(String(key)),
                getSelectedRecordsCount: () => window.__selection.size,
                deselectAllRecords: () => window.__selection.clear(),
            }),
        };
        JS;
    }
}
