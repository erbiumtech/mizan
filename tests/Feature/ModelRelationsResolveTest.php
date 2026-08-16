<?php

namespace Tests\Feature;

use App\Support\ModuleMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Every relation on every model points at a class that exists.
 *
 * This exists because of a bug that a whole-suite run caught and three targeted searches did not.
 * `Bank` moved from `App\Modules\Accounting\Models` to `App\Modules\Core\Models`, and three models in the
 * namespace it left behind — `Beneficiary`, `CompanyBankAccount` and, on the other side of the move,
 * `Contact` — referred to it as a bare `Bank::class` with **no import at all**, because it had been
 * sitting beside them in the same namespace. Every search for the fully-qualified name came back clean.
 * The class was gone and the references were dangling, and the only symptom was
 * `include(.../Accounting/Models/Bank.php): Failed to open stream` when a page happened to render.
 *
 * A grep cannot find this and neither can the module lint: `ModuleBoundaryTest` looks for imports across
 * a module boundary, and a same-namespace reference is the one shape that has no import to find. What
 * does find it is asking the models themselves, which is all this file does — build each relation and let
 * Eloquent try to instantiate the class on the other end.
 *
 * Cheap on purpose: `newRelatedInstance()` is a `new $class`, so nothing here touches the database and
 * the whole file runs in well under a second. It is the closest thing to a compiler this codebase has for
 * the one refactor it performs most often — moving a class between modules.
 */
class ModelRelationsResolveTest extends TestCase
{
    /**
     * Relations that cannot be built without a loaded record.
     *
     * A morphTo reads the type column off the *instance* to decide what to relate to, so calling it on an
     * empty model returns a relation with no target rather than a broken one. There is nothing here to
     * verify and nothing to catch — the morph map is asserted by ModuleCoverageTest instead.
     */
    private const SKIPPED_RETURN_TYPES = [
        \Illuminate\Database\Eloquent\Relations\MorphTo::class,
    ];

    public function test_every_relation_points_at_a_class_that_exists(): void
    {
        $broken = [];
        $checked = 0;

        foreach (ModuleMap::models() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $model = new $class;

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $this->isRelationMethod($method, $reflection)) {
                    continue;
                }

                try {
                    $relation = $model->{$method->getName()}();
                } catch (\Throwable $e) {
                    // The failure this file is for: `new $related` on a class whose file is gone.
                    $broken[] = sprintf('%s::%s() — %s', $class, $method->getName(), $e->getMessage());

                    continue;
                }

                if (! $relation instanceof Relation) {
                    continue;
                }

                $checked++;

                // Belt and braces: a relation can be built from a class string that autoloads to
                // nothing in some configurations, so the far end is asserted rather than assumed.
                $related = $relation->getRelated()::class;

                if (! class_exists($related)) {
                    $broken[] = sprintf('%s::%s() relates to %s, which does not exist', $class, $method->getName(), $related);
                }
            }
        }

        $this->assertSame([], $broken, implode("\n", [
            'These relations name a class that cannot be loaded. The usual cause is a class that moved',
            'between modules while a reference to it stayed behind — and a same-namespace reference has',
            'no import, so searching for the old fully-qualified name finds nothing.',
            '',
            ...$broken,
        ]));

        // Guards the guard. If the discovery or the relation detection below ever stops matching, this
        // file would pass while checking nothing at all — which is the failure it exists to prevent.
        $this->assertGreaterThan(50, $checked, 'too few relations were checked for this to mean anything');
    }

    /**
     * A method that takes no arguments and returns a relation.
     *
     * Detected by return type where one is declared, and by trying it where one is not — this codebase
     * has both, and the untyped ones (`public function bank()`) are exactly the older code most likely to
     * be holding a stale reference.
     */
    private function isRelationMethod(ReflectionMethod $method, ReflectionClass $declaring): bool
    {
        if ($method->getNumberOfParameters() > 0 || $method->isStatic()) {
            return false;
        }

        // Only methods this model declares itself. Inherited framework methods are not relations, and
        // calling them blindly is how this turns into a test of Eloquent.
        if ($method->getDeclaringClass()->getName() !== $declaring->getName()) {
            return false;
        }

        $type = $method->getReturnType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $name = $type->getName();

            if (in_array($name, self::SKIPPED_RETURN_TYPES, true)) {
                return false;
            }

            return is_a($name, Relation::class, true);
        }

        // No declared return type: only worth trying for the naming shape a relation has, since calling
        // an arbitrary accessor could do real work.
        return $type === null && ! str_starts_with($method->getName(), 'get')
            && ! str_starts_with($method->getName(), 'set')
            && ! str_starts_with($method->getName(), 'scope');
    }
}
