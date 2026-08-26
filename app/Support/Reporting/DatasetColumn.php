<?php

namespace App\Support\Reporting;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One column a dataset offers — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * "…then the columns (label, type, whether it groups, whether it aggregates, how it resolves)".
 *
 * **There are exactly three ways a column resolves, and the difference decides what SQL can do with it.**
 * That is the whole reason this is a declaration rather than a string:
 *
 *  - **a column** — a real column on the dataset's own table. Sortable, groupable and aggregatable, because
 *    the database can do all three;
 *  - **a related column** — a value reached through a relation the dataset has declared (`contact.name`).
 *    Displayable and filterable; groupable only where the declaration also names the *local* key to group on,
 *    because `GROUP BY contacts.name` needs a join this builder does not write, while `GROUP BY contact_id`
 *    needs nothing and gives the same buckets;
 *  - **a derived column** — a closure over the model. Displayable and nothing else, enforced here rather than
 *    checked later, because item 5 requires aggregation to happen in SQL and a closure cannot.
 *
 * **The type is what a reader sees, not what the database stores.** `money` and `number` are both decimals;
 * the difference is a thousands separator and a right-aligned column, which is the distinction every report
 * in Phases 1–3 makes by hand through `ReportShapes::table()`'s `numeric` argument. Extracted from those 46
 * tables, the recurring kinds are a subject, a date, an amount, a count and a status — text, date, money,
 * number, boolean.
 *
 * **The key is a storage format.** Phase 6.3 stores a definition naming these keys, so renaming one silently
 * changes what a saved report shows — the same hazard `tests/alias-lock.json` exists for, one level down. Keys
 * are snake_case and unique within a dataset, and `DatasetRegistryTest` enforces both.
 */
final class DatasetColumn
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const DATE = 'date';

    public const BOOLEAN = 'boolean';

    public const TYPES = [self::TEXT, self::NUMBER, self::MONEY, self::DATE, self::BOOLEAN];

    /**
     * What the database can be asked to work out over a column.
     *
     * `sum` and `avg` only make sense on a figure, `min`/`max` on a figure or a date, `count` on anything —
     * which the factories below enforce rather than trusting a declaration to be sensible.
     */
    public const SUM = 'sum';

    public const COUNT = 'count';

    public const AVG = 'avg';

    public const MIN = 'min';

    public const MAX = 'max';

    public const AGGREGATES = [self::SUM, self::COUNT, self::AVG, self::MIN, self::MAX];

    /**
     * @param  string|null  $select  a column on the dataset's own table
     * @param  string|null  $relation  a declared relation path, `relation.attribute`
     * @param  string|null  $groupBy  the local column to group a related column on
     * @param  Closure|null  $derive  `fn (Model $row): mixed`
     * @param  array<int, string>  $aggregates
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $select = null,
        public readonly ?string $relation = null,
        public readonly ?string $groupBy = null,
        public readonly ?Closure $derive = null,
        public readonly array $aggregates = [],
    ) {}

    /**
     * A real column on the dataset's own table.
     *
     * The only kind the database can do everything with, which is why it is the one to reach for: a report
     * that groups by month and totals a figure is two of these and no joins.
     */
    public static function make(string $key, string $label, string $type = self::TEXT, ?string $select = null, bool $groupable = true): self
    {
        return new self(
            key: self::validKey($key),
            label: $label,
            type: self::validType($type),
            select: $select ?? $key,
            groupBy: $groupable ? ($select ?? $key) : null,
            aggregates: self::aggregatesFor($type),
        );
    }

    /**
     * A value through a relation the dataset has declared.
     *
     * `$groupBy` is the local foreign key, and passing it is what makes a related column groupable: the
     * database buckets on `contact_id` and the pane prints the contact's name, which is the same report
     * without a join. Omitting it says "show this, do not group by it".
     */
    public static function related(string $key, string $label, string $relation, string $type = self::TEXT, ?string $groupBy = null): self
    {
        if (! str_contains($relation, '.')) {
            throw new InvalidArgumentException(
                "Related column [{$key}] must name a relation and an attribute, e.g. 'contact.name'."
            );
        }

        return new self(
            key: self::validKey($key),
            label: $label,
            type: self::validType($type),
            relation: $relation,
            groupBy: $groupBy,
            // A relation's own column can still be counted, and counting is how "invoices per customer"
            // gets asked. Summing one would need the join this deliberately avoids.
            aggregates: [self::COUNT],
        );
    }

    /**
     * A value worked out in PHP.
     *
     * Display only, and that is enforced here: no `groupBy`, and `count` is the one aggregate left because
     * counting rows never touches the value. Item 5 asks for aggregation in SQL, and the honest way to hold
     * that line is for the declaration to make the alternative impossible rather than for the query builder to
     * remember.
     *
     * @param  Closure(Model): mixed  $derive
     */
    public static function derived(string $key, string $label, Closure $derive, string $type = self::TEXT): self
    {
        return new self(
            key: self::validKey($key),
            label: $label,
            type: self::validType($type),
            derive: $derive,
            aggregates: [self::COUNT],
        );
    }

    /** Whether the database can group rows on this column. */
    public function isGroupable(): bool
    {
        return $this->groupBy !== null;
    }

    public function canAggregate(string $aggregate): bool
    {
        return in_array($aggregate, $this->aggregates, true);
    }

    /** Whether a figure sits in this column, which is what right-aligns it and what a total may be asked of. */
    public function isNumeric(): bool
    {
        return in_array($this->type, [self::NUMBER, self::MONEY], true);
    }

    /**
     * The value for one row.
     *
     * Three kinds, one reader. The relation path is walked with `getAttribute` rather than `data_get` so a
     * model's accessors and casts apply — a date comes back as a `Carbon`, a decimal as its cast — and a
     * missing relation reads as null instead of throwing on a row whose parent was deleted.
     */
    public function value(Model $row): mixed
    {
        if ($this->derive !== null) {
            return ($this->derive)($row);
        }

        if ($this->relation !== null) {
            $value = $row;

            foreach (explode('.', $this->relation) as $step) {
                $value = $value instanceof Model ? $value->getAttribute($step) : null;
            }

            return $value;
        }

        return $row->getAttribute($this->localName());
    }

    /** The attribute name behind a real column, which is the column name without its table. */
    public function localName(): string
    {
        $select = (string) $this->select;

        return str_contains($select, '.') ? substr($select, strrpos($select, '.') + 1) : $select;
    }

    /**
     * Which aggregates a type may offer.
     *
     * Summing a status is meaningless and averaging a date nearly so; offering them would put nonsense in the
     * builder's dropdown, and a dropdown is where somebody finds out what a feature thinks is sensible.
     *
     * @return array<int, string>
     */
    private static function aggregatesFor(string $type): array
    {
        return match ($type) {
            self::MONEY, self::NUMBER => [self::SUM, self::COUNT, self::AVG, self::MIN, self::MAX],
            self::DATE => [self::COUNT, self::MIN, self::MAX],
            default => [self::COUNT],
        };
    }

    private static function validKey(string $key): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException(
                "Column key [{$key}] must be snake_case: it is stored in saved report definitions."
            );
        }

        return $key;
    }

    private static function validType(string $type): string
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Column type [{$type}] is not one of: ".implode(', ', self::TYPES).'.'
            );
        }

        return $type;
    }
}
