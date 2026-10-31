<?php

namespace App\Support\Replication;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Copies a {@see Replicable} record — docs/replicate-records.md.
 *
 * One transaction on the record's own connection, so the copy, its children and its custom field
 * values arrive together or not at all. The activity entry is written after the commit: the log
 * lives in the landlord database, and a "Replicated from" line pointing at a copy that rolled back
 * would be a lie.
 */
class RecordReplicator
{
    /** Never copied, whatever a model declares. */
    public const ALWAYS_EXCEPT = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * @param  array<string, mixed>  $values  values for the model's unique fields, as the action asked for them
     */
    public function replicate(Model $original, array $values = []): Model
    {
        if (! $original instanceof Replicable) {
            throw new InvalidArgumentException($original::class.' is not replicable.');
        }

        if (! $original->canBeReplicated()) {
            throw new RuntimeException('This record cannot be replicated.');
        }

        $relations = $this->relations($original);

        // Eager, so copying the lines of a long invoice is one query and not one per line.
        $original->loadMissing(array_keys($relations));

        $copy = DB::connection($original->getConnectionName())->transaction(function () use ($original, $values, $relations): Model {
            $copy = $original->replicate(array_values(array_unique([
                ...self::ALWAYS_EXCEPT,
                ...$original->replicationExcept(),
                ...$this->notColumns($original),
            ])));

            foreach ($original->replicationResets() as $attribute => $value) {
                $copy->setAttribute($attribute, $value instanceof Closure ? $value($original, $copy) : $value);
            }

            foreach ($original->replicationUniqueFields() as $attribute => $suggest) {
                $value = array_key_exists($attribute, $values) ? $values[$attribute] : $suggest($original);

                if (blank($value) || static::valueIsTaken($original, $attribute, $value)) {
                    throw new RuntimeException("The {$attribute} for the copy is missing or already taken.");
                }

                $copy->setAttribute($attribute, $value);
            }

            /** @var Model&Replicable $copy */
            $copy = $copy->persistReplica($original);

            foreach ($relations as $name => $except) {
                $this->copyRelation($original, $copy, $name, $except);
            }

            $this->copyCustomFields($original, $copy);

            $copy->afterReplication($original);

            return $copy;
        });

        activity()
            ->performedOn($copy)
            ->causedBy(auth()->user())
            ->event('replicated')
            ->useLog(class_basename($copy))
            ->withProperties(['replicated_from' => ['type' => $original->getMorphClass(), 'id' => $original->getKey()]])
            ->log('Replicated from '.$original->replicationLabel());

        return $copy;
    }

    /**
     * Attributes the original carries that are not columns of its table — `withSum()` / `withCount()`
     * aggregates a list query added (a tax rate's `lines_sum_tax_amount`), selected aliases. Copied as
     * they are, the insert names a column that does not exist.
     *
     * @return array<int, string>
     */
    private function notColumns(Model $original): array
    {
        $columns = $original->getConnection()->getSchemaBuilder()->getColumnListing($original->getTable());

        return array_values(array_diff(array_keys($original->getAttributes()), $columns));
    }

    /**
     * Whether a value is already used — soft-deleted rows included, because a unique index does
     * not know they were deleted. A composite index is honoured through the model's unique scopes.
     */
    public static function valueIsTaken(Model $original, string $attribute, mixed $value): bool
    {
        $query = $original->newQuery()->withoutGlobalScope(SoftDeletingScope::class);

        // A date column is stored with a time on some drivers, so it is compared as a date.
        static::isDateAttribute($original, $attribute)
            ? $query->whereDate($attribute, $value)
            : $query->where($attribute, $value);

        $scopes = $original instanceof Replicable ? ($original->replicationUniqueScopes()[$attribute] ?? []) : [];

        foreach ($scopes as $column) {
            $query->where($column, $original->getAttribute($column));
        }

        return $query->exists();
    }

    /** Whether the model casts the attribute to a date, so the action asks for it with a date picker. */
    public static function isDateAttribute(Model $model, string $attribute): bool
    {
        return in_array($model->getCasts()[$attribute] ?? null, ['date', 'immutable_date'], true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function relations(Replicable $original): array
    {
        $relations = [];

        foreach ($original->replicationRelations() as $key => $value) {
            is_int($key)
                ? $relations[$value] = []
                : $relations[$key] = (array) $value;
        }

        return $relations;
    }

    /**
     * @param  array<int, string>  $except
     */
    private function copyRelation(Model $original, Model $copy, string $name, array $except): void
    {
        $relation = $original->{$name}();
        $related = $original->getRelation($name);

        if ($relation instanceof BelongsToMany) {
            $copy->{$name}()->sync($related->modelKeys());

            return;
        }

        if (! $relation instanceof HasOneOrMany) {
            throw new InvalidArgumentException("Relation [{$name}] on ".$original::class.' cannot be replicated.');
        }

        foreach ($related instanceof Model ? [$related] : $related as $child) {
            $copy->{$name}()->save($child->replicate([...self::ALWAYS_EXCEPT, ...$except]));
        }
    }

    /** Custom field values are stored per record, so a copy without them would lose data silently. */
    private function copyCustomFields(Model $original, Model $copy): void
    {
        if (! method_exists($original, 'customFieldValues')) {
            return;
        }

        foreach ($original->customFieldValues()->get() as $value) {
            // Stored values are copied as they are — an encrypted field's ciphertext included —
            // so nothing is decrypted on the way.
            $copy->customFieldValues()->create([
                'custom_field_id' => $value->custom_field_id,
                'value' => $value->value,
            ]);
        }
    }
}
