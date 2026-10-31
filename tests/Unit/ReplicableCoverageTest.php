<?php

namespace Tests\Unit;

use App\Support\Replication\Replicable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Replicate action and the models that declare themselves replicable stay in step —
 * docs/replicate-records.md.
 *
 * Three ways to get it wrong without a single feature test noticing: declare a model replicable and
 * forget its screens (the button never appears), put the button on a resource whose model is not
 * replicable (it never shows, silently), or name a child relation that does not exist (the copy
 * throws only when someone presses it). And one that is a decision rather than a mistake: the
 * records the document excludes on purpose must not quietly become replicable.
 */
class ReplicableCoverageTest extends TestCase
{
    /** Excluded on purpose, each for a reason docs/replicate-records.md gives. */
    private const NEVER_REPLICABLE = [
        'App\Modules\Accounting\Models\JournalEntry',
        'App\Modules\Accounting\Models\JournalEntryLine',
        'App\Modules\Accounting\Models\Payment',
        'App\Modules\Accounting\Models\Cheque',
        'App\Modules\Accounting\Models\BankStatement',
        'App\Modules\Attendance\Models\AttendanceDay',
        'App\Modules\Core\Models\ActivityLog',
        'App\Modules\Core\Models\Company',
        'App\Modules\Core\Models\Role',
        'App\Modules\Core\Models\User',
        'App\Modules\Core\Models\WebhookEndpoint',
        'App\Modules\Invoicing\Models\FbrSubmission',
        'App\Modules\Payroll\Models\PayrollRun',
        'App\Modules\Payroll\Models\Payslip',
        'App\Modules\Work\Models\IntegrationConnection',
    ];

    public function test_every_replicable_model_offers_the_action_and_only_those_do(): void
    {
        $replicable = $this->replicableModels();
        $this->assertNotEmpty($replicable, 'the scan found no replicable models, so it is not scanning');

        $withoutAction = [];
        $withoutModel = [];

        foreach (glob($this->root().'/app/Modules/*/Filament/Resources/*/*Resource.php') as $resource) {
            $source = (string) file_get_contents($resource);

            if (! preg_match('/protected static \?string \$model = (\w+)::class;/', $source, $match)
                || ! preg_match('/^use ([\w\\\\]+\\\\'.$match[1].');$/m', $source, $import)) {
                continue;
            }

            $model = $import[1];
            $offers = $this->directoryMentions(dirname($resource), 'ReplicateRecordAction');

            if (in_array($model, $replicable, true)) {
                // Both places a person meets the record: its row in the list (the table, which the resource
                // or its Tables class defines) and its own page's header, when it has an edit or view page.
                $onList = $this->listMentions(dirname($resource), 'ReplicateRecordAction');
                $hasRecordPage = (bool) glob(dirname($resource).'/Pages/{Edit,View}*.php', GLOB_BRACE);
                $onPage = ! $hasRecordPage || $this->directoryMentions(dirname($resource).'/Pages', 'ReplicateRecordAction', '/^(Edit|View)/');

                if (! $onList || ! $onPage) {
                    $withoutAction[] = basename($resource).' ('.$model.')'.(! $onList ? ' — not on the list' : '').(! $onPage ? ' — not on its page' : '');
                }
            }

            if (! in_array($model, $replicable, true) && $offers) {
                $withoutModel[] = basename($resource).' ('.$model.')';
            }
        }

        $this->assertSame([], $withoutAction, "These models are replicable but their resource never shows Replicate:\n".implode("\n", $withoutAction));
        $this->assertSame([], $withoutModel, "These resources show Replicate for a model that is not Replicable, so it never appears:\n".implode("\n", $withoutModel));
    }

    public function test_every_declared_child_relation_exists(): void
    {
        $missing = [];

        foreach ($this->replicableModels() as $class) {
            /** @var Model&Replicable $model */
            $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();

            foreach ($model->replicationRelations() as $key => $value) {
                $relation = is_int($key) ? $value : $key;

                if (! method_exists($model, $relation)) {
                    $missing[] = "{$class}::{$relation}()";
                }
            }

            foreach ($model->replicationUniqueScopes() as $attribute => $columns) {
                if (! array_key_exists($attribute, $model->replicationUniqueFields())) {
                    $missing[] = "{$class}: unique scope for [{$attribute}], which is not a unique field";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_excluded_records_stay_excluded(): void
    {
        $offenders = array_values(array_filter(
            self::NEVER_REPLICABLE,
            fn (string $class): bool => class_exists($class) && is_subclass_of($class, Replicable::class),
        ));

        $this->assertSame([], $offenders, 'docs/replicate-records.md excludes these on purpose; change the document first.');
    }

    /** @return array<int, class-string> */
    private function replicableModels(): array
    {
        $models = [];

        foreach (glob($this->root().'/app/Modules/*/Models/*.php') as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^namespace ([\w\\\\]+);/m', $source, $namespace)
                && preg_match('/^(?:final )?class (\w+) extends \w+ implements [^{]*\bReplicable\b/m', $source, $class)) {
                $models[] = $namespace[1].'\\'.$class[1];
            }
        }

        return $models;
    }

    private function directoryMentions(string $directory, string $needle, ?string $basenamePattern = null): bool
    {
        if (! is_dir($directory)) {
            return false;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || ($basenamePattern && ! preg_match($basenamePattern, $file->getBasename()))) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The list's row actions: a `recordActions([...])` call naming the action, outside the record pages. */
    private function listMentions(string $directory, string $needle): bool
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/RelationManagers/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $offset = 0;

            while (($at = strpos($source, '->recordActions(', $offset)) !== false) {
                $offset = $at + 1;
                $open = strpos($source, '(', $at);
                $depth = 0;

                // The call's own parentheses, so a nested closure or array does not end it early.
                for ($i = $open, $length = strlen($source); $i < $length; $i++) {
                    $depth += match ($source[$i]) {
                        '(' => 1,
                        ')' => -1,
                        default => 0,
                    };

                    if ($depth === 0) {
                        break;
                    }
                }

                if (str_contains(substr($source, $open, $i - $open), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
