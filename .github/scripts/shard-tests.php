<?php

declare(strict_types=1);

/*
 * Rewrites phpunit.xml so it lists one shard of the test files, for CI to run
 * the suite on several runners at once.
 *
 *   php .github/scripts/shard-tests.php <shard> <of>
 *
 * Every *Test.php under tests/ is weighed by how many test methods it declares
 * and dealt greedily to the lightest shard so far, heaviest files first. The
 * split is a pure function of the tree, so every runner computes the same
 * one and the shards together are exactly the whole suite — no file is in two
 * of them and none is in none.
 *
 * It edits phpunit.xml in place rather than writing a second file because
 * `php artisan test` always passes its own --configuration=phpunit.xml and
 * drops the one on the command line. The <php> block and everything else in
 * the file are kept as they are; only <testsuites> changes.
 */

[$shard, $of] = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0)];

if ($shard < 1 || $of < 1 || $shard > $of) {
    fwrite(STDERR, "usage: shard-tests.php <shard 1..N> <N>\n");
    exit(64);
}

$root = dirname(__DIR__, 2);
$configuration = $root.'/phpunit.xml';

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if (! str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    $weight = preg_match_all('/^\s*(?:public\s+)?function\s+test\w+|#\[(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\]/m', $source);

    $files[substr($file->getPathname(), strlen($root) + 1)] = max($weight, 1);
}

// Heaviest first; ties by path so the order never depends on the filesystem.
uksort($files, fn (string $a, string $b): int => [$files[$b], $a] <=> [$files[$a], $b]);

$shards = array_fill(1, $of, ['weight' => 0, 'files' => []]);

foreach ($files as $path => $weight) {
    $lightest = 1;
    foreach ($shards as $index => $candidate) {
        if ($candidate['weight'] < $shards[$lightest]['weight']) {
            $lightest = $index;
        }
    }

    $shards[$lightest]['weight'] += $weight;
    $shards[$lightest]['files'][] = $path;
}

$mine = $shards[$shard]['files'];
sort($mine);

$xml = (string) file_get_contents($configuration);
$entries = implode("\n", array_map(fn (string $path): string => "            <file>{$path}</file>", $mine));
$suite = "<testsuites>\n        <testsuite name=\"Shard {$shard} of {$of}\">\n{$entries}\n        </testsuite>\n    </testsuites>";

$rewritten = preg_replace('#<testsuites>.*?</testsuites>#s', $suite, $xml, 1, $count);

if ($count !== 1) {
    fwrite(STDERR, "phpunit.xml has no <testsuites> block to replace\n");
    exit(65);
}

file_put_contents($configuration, $rewritten);

printf(
    "Shard %d of %d: %d files, %d test methods (shards weigh %s)\n",
    $shard,
    $of,
    count($mine),
    $shards[$shard]['weight'],
    implode(', ', array_column($shards, 'weight')),
);
