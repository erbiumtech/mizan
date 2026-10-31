<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A list's default order with the records awaiting approval on top.
 *
 * Each model that goes through an approval step says once, in its `PENDING_APPROVAL` constant, which
 * column holds that state and which values mean "waiting for someone to approve it":
 *
 *     public const PENDING_APPROVAL = ['status', ['pending']];         // usually its STATUS_ constant
 *     public const PENDING_APPROVAL = ['approved_at', null];   // waiting while the column is empty
 *
 * A table then passes its old default to `PendingFirst::sort()` instead of to `defaultSort()` directly:
 *
 *     ->defaultSort(PendingFirst::sort(LeaveRequest::class, 'from_date', 'desc'))
 *
 * Filament applies a closure default after any column the reader has clicked, so a clicked header still
 * decides the order and this only breaks its ties. The values are bound, never written into the SQL,
 * and the expression is a plain CASE, so it reads the same on SQLite, MySQL and PostgreSQL.
 */
final class PendingFirst
{
    /**
     * @param  class-string<Model>  $model  a model declaring `PENDING_APPROVAL`
     * @param  string  $then  the column the list was sorted by before (on the model's own table)
     */
    public static function sort(string $model, string $then = 'id', string $direction = 'desc'): Closure
    {
        // Read once, when the table is configured, so a model without the declaration fails loudly
        // on the page rather than sorting nothing.
        [$column, $values] = self::declaration($model);
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return static function (Builder $query) use ($column, $values, $then, $direction): Builder {
            $table = $query->getModel()->getTable();
            $grammar = $query->getQuery()->getGrammar();
            $qualified = $grammar->wrap($table.'.'.$column);

            if ($values === null) {
                $query->orderByRaw("CASE WHEN {$qualified} IS NULL THEN 0 ELSE 1 END");
            } else {
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $query->orderByRaw("CASE WHEN {$qualified} IN ({$placeholders}) THEN 0 ELSE 1 END", $values);
            }

            return $query->orderBy($table.'.'.$then, $direction);
        };
    }

    /**
     * Whether a record is waiting for approval, by the same declaration the list sorts on.
     */
    public static function isPending(Model $record): bool
    {
        [$column, $values] = self::declaration($record::class);
        $state = $record->getAttribute($column);

        return $values === null ? $state === null : in_array($state, $values, true);
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{0: string, 1: list<string>|null}
     */
    public static function declaration(string $model): array
    {
        if (! defined($model.'::PENDING_APPROVAL')) {
            throw new InvalidArgumentException("{$model} does not declare PENDING_APPROVAL.");
        }

        $declaration = constant($model.'::PENDING_APPROVAL');

        if (
            ! is_array($declaration)
            || ! is_string($declaration[0] ?? null)
            || ! array_key_exists(1, $declaration)
            || ! (is_array($declaration[1]) || $declaration[1] === null)
        ) {
            throw new InvalidArgumentException("{$model}::PENDING_APPROVAL must be [column, values] or [column, null].");
        }

        if (is_array($declaration[1]) && $declaration[1] === []) {
            throw new InvalidArgumentException("{$model}::PENDING_APPROVAL names no pending values.");
        }

        return [$declaration[0], is_array($declaration[1]) ? array_values($declaration[1]) : null];
    }
}
