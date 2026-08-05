<?php

namespace Tests\Feature;

use App\Support\ModuleMap;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every resource and standalone page in the app is expected to carry a "Help"
 * header action (App\Filament\Support\HelpAction) backed by a markdown file
 * under resources/markdown/help/.
 *
 * Filesystem-driven, like ModuleCoverageTest, rather than rendering all ~60
 * pages through Livewire: each page's seeding needs differ too widely for one
 * generic render harness to cover reliably, and the change that wires a page's
 * HelpAction already proves it renders at that point. What decays silently
 * afterward isn't "does it render" — it's "did the next new resource remember
 * Help at all," which a source-level check catches just as well, much faster,
 * and without inventing fake data for every module.
 */
class HelpCoverageTest extends TestCase
{
    /**
     * Line-item resources reached only via a relation manager tab on their
     * parent (`shouldRegisterNavigation = false`, no List page of their own
     * meant to be reached directly) — their help lives in the parent
     * resource's doc instead of a file of their own.
     *
     * @var array<int, class-string>
     */
    private const NO_OWN_HELP = [
        \App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource::class,
        \App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource::class,
        \App\Modules\Invoicing\Filament\Resources\InvoiceLines\InvoiceLineResource::class,
    ];

    public function test_every_resource_list_page_offers_help(): void
    {
        $missing = [];

        foreach (ModuleMap::resources() as $resource) {
            if (in_array($resource, self::NO_OWN_HELP, true)) {
                continue;
            }

            $listPage = ($resource::getPages()['index'] ?? null)?->getPage();

            if ($listPage === null || $this->helpSlugIn($listPage) === null) {
                $missing[] = $listPage ?? $resource;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These resources\' list pages carry no HelpAction::make(...) call.',
            'Every resource is expected to offer in-app help — see ListJournalEntries',
            'for the pattern.',
            '',
            ...$missing,
        ]));
    }

    public function test_every_standalone_page_offers_help(): void
    {
        $missing = [];

        foreach (ModuleMap::pages() as $page) {
            if ($this->helpSlugIn($page) === null) {
                $missing[] = $page;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These pages carry no HelpAction::make(...) call.',
            '',
            ...$missing,
        ]));
    }

    public function test_every_help_action_points_at_a_real_markdown_file(): void
    {
        $dangling = [];

        foreach ([...ModuleMap::resources(), ...ModuleMap::pages()] as $class) {
            if (in_array($class, self::NO_OWN_HELP, true)) {
                continue;
            }

            $target = $this->isFilamentPage($class)
                ? $class
                : ($class::getPages()['index'] ?? null)?->getPage();

            $slug = $target === null ? null : $this->helpSlugIn($target);

            if ($slug === null) {
                continue; // reported by the coverage tests above already
            }

            if (! File::exists(resource_path("markdown/help/{$slug}.md"))) {
                $dangling[] = "{$target} → resources/markdown/help/{$slug}.md";
            }
        }

        $this->assertSame([], $dangling, implode("\n", [
            'These HelpAction slugs have no matching markdown file:',
            '',
            ...$dangling,
        ]));
    }

    private function isFilamentPage(string $class): bool
    {
        return (new ReflectionClass($class))->isSubclassOf(\Filament\Pages\Page::class);
    }

    private function helpSlugIn(string $class): ?string
    {
        $source = File::get((new ReflectionClass($class))->getFileName());

        if (! preg_match("/HelpAction::make\(\s*'([a-z0-9-]+)'/", $source, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
