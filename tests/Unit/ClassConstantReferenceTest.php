<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Every `self::NAME` and `static::NAME` constant a class under app/ reads is one it actually has.
 *
 * PHP only notices a missing class constant when the line runs, so a constant removed in one place and
 * still read in another passes every test that does not happen to call that method. That is how two
 * model constants turned into admin-managed option lists left `EmploymentContract::typeLabel()` and
 * `ContactDocument::kindLabel()` reading constants that were gone (#69): the sweep searched for
 * `Class::NAME` and missed `self::NAME`. This asks PHP itself, through reflection, so constants of any
 * visibility from the class, its parents, interfaces and traits, and enum cases, all count.
 */
class ClassConstantReferenceTest extends TestCase
{
    public function test_every_self_or_static_constant_reference_resolves(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // A trait's or interface's `self::` means the class that uses or implements it, so it is
            // checked there instead.
            if (preg_match('/^\s*(?:trait|interface)\s+\w+/m', $source)) {
                continue;
            }

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                || ! preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum)\s+(\w+)/m', $source, $class)) {
                continue;
            }

            $fqcn = $namespace[1].'\\'.$class[1];

            try {
                if (! class_exists($fqcn) && ! enum_exists($fqcn)) {
                    continue;
                }
            } catch (Throwable) {
                // A class whose parent belongs to a package not installed here cannot be loaded; it
                // cannot run either, so there is nothing to check.
                continue;
            }

            preg_match_all('/\b(?:self|static)::([A-Z][A-Z0-9_]+)\b(?!\s*\()/', $source, $references, PREG_OFFSET_CAPTURE);

            $reflection = new ReflectionClass($fqcn);

            foreach ($references[1] as [$name, $offset]) {
                if (! $reflection->hasConstant($name)) {
                    $line = substr_count($source, "\n", 0, $offset) + 1;
                    $missing[] = substr($file->getPathname(), strlen($root) + 1).":{$line} reads {$fqcn}::{$name}";
                }
            }
        }

        $this->assertSame([], $missing, "These read a class constant the class does not have:\n".implode("\n", $missing));
    }
}
