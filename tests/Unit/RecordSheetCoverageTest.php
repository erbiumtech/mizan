<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Side sheets — docs/side-sheets.md.
 *
 * Read from the source, like ReplicableCoverageTest, because what matters is what each file declares:
 *
 *  - every Filament resource says where its records open, so a new resource makes the choice;
 *  - the list page of a resource that opens a sheet carries the trait that acts on it, and no other
 *    list page does (it would do nothing, and suggest otherwise);
 *  - a `Sheet` resource meets the rules for one: its edit page adds nothing to Filament's save, it has
 *    no relation managers and its form no repeaters — because the edit sheet runs Filament's edit
 *    action, not that page, and anything the page did on save would silently stop happening;
 *  - the document lists every resource and the choice it made.
 */
class RecordSheetCoverageTest extends TestCase
{
    private const DECLARATION = '/protected static RecordSheet \$recordSheet = RecordSheet::(Sheet|SheetView|Page);/';

    /** What an edit page may override and still be one the edit sheet can stand in for. */
    private const HARMLESS_EDIT_PAGE_METHODS = ['getHeaderActions', 'getRedirectUrl'];

    public function test_every_resource_declares_where_its_records_open(): void
    {
        $missing = [];

        foreach ($this->resources() as $file => $source) {
            if (! preg_match(self::DECLARATION, $source)) {
                $missing[] = $this->relative($file);
            }
        }

        $this->assertSame([], $missing, "These resources do not declare `protected static RecordSheet \$recordSheet` — see docs/side-sheets.md:\n".implode("\n", $missing));
    }

    public function test_the_list_page_carries_the_trait_exactly_when_the_resource_opens_a_sheet(): void
    {
        $wrong = [];

        foreach ($this->resources() as $file => $source) {
            $choice = $this->choice($source);
            $list = $this->pageSource($file, $source, 'index');

            if ($list === null) {
                continue;
            }

            $hasTrait = str_contains($list, 'use OpensRecordsInSheets') || preg_match('/^    use [^;]*\bOpensRecordsInSheets\b/m', $list);

            if ($choice !== 'Page' && ! $hasTrait) {
                $wrong[] = $this->relative($file).': opens a sheet, but its list page lacks OpensRecordsInSheets';
            }

            if ($choice === 'Page' && $hasTrait) {
                $wrong[] = $this->relative($file).': opens pages, but its list page uses OpensRecordsInSheets';
            }

            // A list page's own table() or getDefaultActionUrl() would silently replace the trait's.
            if ($hasTrait && preg_match('/function (table|getDefaultActionUrl|mountAction|unmountAction)\(/', $list, $m)) {
                $wrong[] = $this->relative($file).": its list page defines {$m[1]}(), which hides the trait's";
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    public function test_every_sheet_resource_meets_the_rules_for_one(): void
    {
        $wrong = [];

        foreach ($this->resources() as $file => $source) {
            if ($this->choice($source) !== 'Sheet') {
                continue;
            }

            $name = $this->relative($file);
            $edit = $this->pageSource($file, $source, 'edit');

            if ($edit === null) {
                $wrong[] = "{$name}: has no edit page for the sheet to stand in for";

                continue;
            }

            preg_match_all('/function (\w+)\(/', $edit, $methods);
            $extra = array_diff($methods[1], self::HARMLESS_EDIT_PAGE_METHODS);

            if ($extra !== []) {
                $wrong[] = "{$name}: its edit page overrides ".implode(', ', $extra).'(), which the sheet would skip';
            }

            if (str_contains($edit, 'InteractsWithCustomFields')) {
                $wrong[] = "{$name}: its edit page saves custom fields, which the sheet would skip";
            }

            if (preg_match('/function getRelations\(\)[^{]*\{\s*return\s*\[\s*[^\]\s]/', $source)) {
                $wrong[] = "{$name}: has relation managers, which a sheet has nowhere to show";
            }

            $form = $this->formSource($file, $source);

            if (preg_match('/\b(Repeater|Builder|TableRepeater)::make\(/', $form)) {
                $wrong[] = "{$name}: its form has a repeater or builder";
            }

            if (preg_match('/EditRecord|CreateRecord|\$livewire\b/', $form)) {
                $wrong[] = "{$name}: its form reads its Livewire page, which in a sheet is the list";
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    public function test_the_document_lists_every_resource_and_its_choice(): void
    {
        $doc = (string) file_get_contents($this->root().'/docs/side-sheets.md');
        $labels = ['Sheet' => 'sheet', 'SheetView' => 'sheet-view', 'Page' => 'page'];
        $wrong = [];

        foreach ($this->resources() as $file => $source) {
            $class = basename($file, '.php');
            $label = $labels[$this->choice($source)];

            if (! preg_match('/\| `'.preg_quote($class, '/').'` \| '.preg_quote($label, '/').' \|/', $doc)) {
                $wrong[] = "{$class} ({$label})";
            }
        }

        $this->assertSame([], $wrong, "docs/side-sheets.md does not list these with their choice:\n".implode("\n", $wrong));
    }

    /** @return array<string, string> Filament resource files, by path, with their source. */
    private function resources(): array
    {
        $resources = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/app', \RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, 'Resource.php') || ! str_contains($path, '/Filament/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (preg_match('/^(?:abstract )?class \w+ extends Resource\b/m', $source) && ! str_contains($source, 'abstract class')) {
                $resources[$path] = $source;
            }
        }

        ksort($resources);

        $this->assertNotEmpty($resources, 'no resources found — has the layout moved?');

        return $resources;
    }

    private function choice(string $source): string
    {
        return preg_match(self::DECLARATION, $source, $m) ? $m[1] : 'Page';
    }

    /** The source of one of the resource's pages, found by the class its getPages() routes to. */
    private function pageSource(string $file, string $source, string $page): ?string
    {
        if (! preg_match("/'{$page}'\s*=>\s*(\w+)::route/", $source, $m)) {
            return null;
        }

        foreach ($this->filesUnder(dirname($file)) as $path) {
            if (basename($path) === $m[1].'.php') {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    /** The form schema: the Schemas/*Form*.php files beside the resource, or the resource itself. */
    private function formSource(string $file, string $source): string
    {
        $form = '';

        foreach ($this->filesUnder(dirname($file)) as $path) {
            if (str_contains($path, '/Schemas/') && str_contains(basename($path), 'Form')) {
                $form .= (string) file_get_contents($path);
            }
        }

        return $form !== '' ? $form : $source;
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen($this->root()) + 1);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
