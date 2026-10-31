<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every relation manager loads after its record page, in its own request.
 *
 * Filament relation managers are lazy unless a class sets `$isLazy = false`. A lazy manager lets the record
 * render first and its related records arrive behind a skeleton; an eager one makes every record page wait
 * for (and query) every related table before anything shows. Kept as a scan rather than a reflection walk
 * so it needs no application to boot.
 */
class RelationManagersLoadLazilyTest extends TestCase
{
    public function test_no_relation_manager_turns_lazy_loading_off(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \RecursiveDirectoryIterator::SKIP_DOTS));

        $managers = 0;
        $eager = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/^(?:final |abstract )?class \w+ extends \w*RelationManager\b/m', $source)) {
                continue;
            }

            $managers++;

            if (preg_match('/\$isLazy\s*=\s*false/', $source)) {
                $eager[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertGreaterThan(0, $managers, 'No relation managers were found; the scan is looking in the wrong place.');
        $this->assertSame([], $eager, "These relation managers load with their page instead of after it:\n".implode("\n", $eager));
    }
}
