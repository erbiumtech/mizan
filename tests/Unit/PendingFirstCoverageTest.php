<?php

namespace Tests\Unit;

use App\Filament\Support\PendingFirst;
use PHPUnit\Framework\TestCase;

/**
 * Every list of records that wait for approval puts them first — App\Filament\Support\PendingFirst.
 *
 * Three checks, all from the source:
 *   - a model whose states say "waiting" (pending, submitted, requested, pending approval) declares
 *     PENDING_APPROVAL, unless it is one of the exceptions below with the reason it is not an approval;
 *   - each declaration is well formed;
 *   - every resource and relation manager that lists a declaring model sorts with PendingFirst::sort().
 */
class PendingFirstCoverageTest extends TestCase
{
    /** States named like a queue that are not somebody's approval, and why. */
    private const NOT_AN_APPROVAL = [
        'App\\Modules\\Campaigns\\Models\\CampaignSend' => 'a send waiting for the queue, not for a person',
        'App\\Modules\\Core\\Models\\WebhookDelivery' => 'a delivery waiting to be attempted',
        'App\\Modules\\Core\\Models\\ReportDelivery' => 'a delivery waiting to be sent',
        'App\\Modules\\ConstructionQhse\\Models\\Inspection' => 'a requested inspection waits to be scheduled, not approved',
        'App\\Modules\\Performance\\Models\\Review' => 'a review not yet started; its steps are writing, not approving',
        'App\\Modules\\Payroll\\Models\\Payslip' => 'the employee acknowledging their own payslip; listed by run',
        'App\\Modules\\Construction\\Models\\DocumentRevision' => 'no list of its own; revisions show inside their document',
    ];

    private const WAITING_STATES = '/const (STATUS_PENDING|STATUS_PENDING_APPROVAL|STATUS_SUBMITTED|STATUS_REQUESTED|APPROVAL_PENDING|REVIEW_PENDING) = /';

    public function test_every_model_with_a_waiting_state_declares_what_is_pending(): void
    {
        $missing = [];

        foreach ($this->models() as $class => $source) {
            if (preg_match(self::WAITING_STATES, $source) && ! $this->declares($source) && ! isset(self::NOT_AN_APPROVAL[$class])) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, "These models have a waiting state but no PENDING_APPROVAL, so their lists cannot put it first:\n".implode("\n", $missing));
    }

    public function test_every_declaration_is_well_formed(): void
    {
        $declaring = array_keys(array_filter($this->models(), fn (string $source): bool => $this->declares($source)));

        $this->assertNotEmpty($declaring);

        foreach ($declaring as $class) {
            [$column, $values] = PendingFirst::declaration($class);

            $this->assertNotSame('', $column, $class);
            $this->assertTrue($values === null || $values !== [], $class);
        }
    }

    public function test_every_list_of_a_declaring_model_sorts_pending_first(): void
    {
        $declaring = array_filter($this->models(), fn (string $source): bool => $this->declares($source));
        $offenders = [];
        $lists = 0;

        foreach ($this->filamentFiles() as $path => $source) {
            $model = $this->listedModel($path, $source);

            if ($model === null || ! isset($declaring[$model])) {
                continue;
            }

            $lists++;
            $short = substr($model, strrpos($model, '\\') + 1);

            if (! str_contains($this->tableSource($path, $source), "PendingFirst::sort({$short}::class")) {
                $offenders[] = str_replace($this->root().'/', '', $path)." lists {$short}";
            }
        }

        $this->assertGreaterThan(0, $lists);
        $this->assertSame([], $offenders, "These lists hold records awaiting approval but do not put them first:\n".implode("\n", $offenders));
    }

    /**
     * The model a resource ($model = X::class) or relation manager lists, resolved through its imports.
     * Relation managers are matched by the related model their relationship returns.
     *
     * @return class-string|null
     */
    private function listedModel(string $path, string $source): ?string
    {
        if (preg_match('/protected static \?string \$model = (\w+)::class;/', $source, $m)) {
            return $this->resolve($m[1], $source);
        }

        if (str_contains($path, '/RelationManagers/') && preg_match("/protected static string \\\$relationship = '(\w+)';/", $source, $m)) {
            return $this->relatedModel($path, $m[1]);
        }

        return null;
    }

    /** For a relation manager: the owner resource's model, then the relationship method on it. */
    private function relatedModel(string $path, string $relationship): ?string
    {
        $resourceDir = dirname($path, 2);
        $resource = glob($resourceDir.'/*Resource.php')[0] ?? null;

        if ($resource === null) {
            return null;
        }

        $resourceSource = (string) file_get_contents($resource);

        if (! preg_match('/protected static \?string \$model = (\w+)::class;/', $resourceSource, $m)) {
            return null;
        }

        $owner = $this->resolve($m[1], $resourceSource);
        $ownerSource = $owner === null ? null : ($this->models()[$owner] ?? null);

        if ($ownerSource === null || ! preg_match('/function '.$relationship.'\(\)[^{]*\{\s*return \$this->\w+\((\w+)::class/', $ownerSource, $r)) {
            return null;
        }

        return $this->resolve($r[1], $ownerSource, $owner);
    }

    /** @return class-string|null */
    private function resolve(string $short, string $source, ?string $self = null): ?string
    {
        if (preg_match('/^use ([\w\\\\]+\\\\'.$short.');/m', $source, $m)) {
            return $m[1];
        }

        if (preg_match('/^use ([\w\\\\]+) as '.$short.';/m', $source, $m)) {
            return $m[1];
        }

        if (preg_match('/^namespace ([\w\\\\]+);/m', $source, $ns)) {
            $candidate = $ns[1].'\\'.$short;

            return isset($this->models()[$candidate]) || $self !== null ? $candidate : null;
        }

        return null;
    }

    /** A resource's table may live in Tables/*Table.php beside it. */
    private function tableSource(string $path, string $source): string
    {
        if (str_contains($path, '/RelationManagers/')) {
            return $source;
        }

        foreach (glob(dirname($path).'/Tables/*Table.php') ?: [] as $table) {
            $source .= (string) file_get_contents($table);
        }

        return $source;
    }

    private function declares(string $source): bool
    {
        return (bool) preg_match('/const PENDING_APPROVAL = /', $source);
    }

    /** @return array<class-string, string> */
    private function models(): array
    {
        static $models = null;

        if ($models !== null) {
            return $models;
        }

        $models = [];

        foreach (glob($this->root().'/app/Modules/*/Models/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^namespace ([\w\\\\]+);/m', $source, $ns)) {
                $models[$ns[1].'\\'.basename($file, '.php')] = $source;
            }
        }

        return $models;
    }

    /** @return array<string, string> */
    private function filamentFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/app', \RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->getExtension() === 'php' && str_contains($path, '/Filament/') && (str_ends_with($path, 'Resource.php') || str_contains($path, '/RelationManagers/'))) {
                $files[$path] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
