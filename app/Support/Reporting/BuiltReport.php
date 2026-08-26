<?php

namespace App\Support\Reporting;

use App\Modules\Core\Models\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Carbon;

/**
 * A saved definition, drawn — `docs/reports-expansion-plan.md` Phase 6, items 4 and 5.
 *
 * > **Rendered through `ReportPane`.** A built report is a `table` (or the `matrix` of Phase 0.2) with a
 * > `footer`, so it inherits the sticky header, the record row, the URL state and Phase 4's export without
 * > knowing they exist.
 *
 * Which is the whole of item 4: there is no second renderer, no second export and no view of its own. This
 * class turns a `ReportDefinition` into the same `table` payload the thirty coded reports produce, through
 * the same `ReportShapes::table()`, and every screen that draws one of those draws this.
 *
 * **The date the pane already carries is what a relative period resolves against.** A definition stores
 * `last_month`, not two dates (Phase 6.2), so it needs a day to be relative *to* — and the pane has one in
 * the URL. `?asOf=2027-03-15` with `last_month` is February 2027, which means the link somebody sends
 * carries the period as well as the report, exactly as it does for every coded report.
 *
 * **Item 5's cost guard is a refusal, not a truncation, and that decides two other things.** The plan asks
 * for "a refusal — *this report asks for too much, narrow the period* — in place of a timeout"; refusing
 * rather than showing the first thousand rows is what keeps the record row honest, because a footer under a
 * truncated table states a total for rows nobody can see. It is also why the footer may be summed in PHP:
 * a report that renders at all has rendered *every* row it matched, so the total of the rendered rows and
 * the total of the query are the same number by construction.
 *
 * **What cannot be totalled is not totalled**, which is Phase 6.1's refusal kept rather than quietly undone.
 * Only a real column of the dataset's own table reaches the footer: a derived column is a closure over each
 * row, and "outstanding by customer" being unsummable is the reason the coded ageing report exists. Summing
 * it here in PHP would answer that question in the one place the registry was careful not to.
 *
 * **A grouped report shows the group and the aggregates, and nothing else.** A column that is neither
 * grouped on nor aggregated has no value per bucket — SQL will not give one and picking a row's would be an
 * invention — so it is dropped from the grid rather than shown empty.
 */
class BuiltReport
{
    use ReportShapes;

    /**
     * The most rows a built report will draw — item 5's explicit ceiling.
     *
     * **A reading limit as much as a query one.** A thousand rows is already more than anybody reads on a
     * screen, and the honest answer to a report that wants more is a narrower period rather than a longer
     * page: this is the one feature in the plan whose cost the *user* chooses, so the ceiling has to be the
     * application's. It is deliberately well below the point where the query itself hurts, because the pane
     * renders every row as markup and `PanelPerformanceTest` holds the hub to a page-size ceiling.
     */
    public const MAX_ROWS = 1000;

    /**
     * When a table declares itself wide enough to need sideways scrolling.
     *
     * Seven columns, from the same measurement Phase 0.2 made: `.fi-explorer-statement` is `overflow: clip`,
     * so a table wider than the pane loses columns silently. A built report is the one report nobody has
     * checked the width of, so it decides for itself from the only thing it knows.
     */
    private const WIDE_AT = 7;

    /** What a column header calls each aggregate. */
    private const AGGREGATE_WORDS = [
        DatasetColumn::SUM => 'sum',
        DatasetColumn::COUNT => 'count',
        DatasetColumn::AVG => 'average',
        DatasetColumn::MIN => 'lowest',
        DatasetColumn::MAX => 'highest',
    ];

    /**
     * The aggregates a record row may add up.
     *
     * A total of sums is a sum and a total of counts is a count. A total of averages is not an average, and a
     * total of minimums is nothing at all — so those columns keep their buckets and the footer leaves them
     * blank, rather than printing a figure whose only merit is that it is a number.
     */
    private const ADDITIVE = [DatasetColumn::SUM, DatasetColumn::COUNT];

    /**
     * A report by the key the hub, the URL and `ReportRenderers` use — `custom-7`.
     *
     * Null for a key that names no readable definition, which is the same answer the pane gives for a report
     * it cannot draw: a deleted row, somebody else's unshared report, a module since switched off.
     *
     * @return array<string, mixed>|null
     */
    public function forKey(?string $key, string $asOf): ?array
    {
        $definition = ReportDefinition::forKey($key);

        return $definition === null ? null : $this->for($definition, $asOf);
    }

    /**
     * One definition, as a pane payload.
     *
     * @return array<string, mixed>|null null when the reader may not report on this subject
     */
    public function for(ReportDefinition $definition, string $asOf): ?array
    {
        $class = $definition->dataset();

        if ($class === null) {
            return null;
        }

        $state = $definition->settings();
        $range = RelativePeriod::range($state['period'], $asOf);
        $query = $this->query($class, $state, $range, $asOf);

        return $state['group_by'] === null
            ? $this->listing($definition, $class, $state, $range, $asOf, $query)
            : $this->grouped($definition, $class, $state, $range, $asOf, $query);
    }

    // ───────────────────────────────────────────────────────────────── the query

    /**
     * The rows this report is over, before columns or grouping.
     *
     * `$class::query()` rather than a table: the dataset's own base query carries tenancy, every global scope
     * and `EmployeeAccess` where the subject has an employee dimension — item 2's "row-level access is
     * inherited, not re-implemented", which is the reason nothing here builds a query of its own.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     */
    private function query(string $class, array $state, array $range, string $asOf): Builder
    {
        $query = $class::query();

        /*
         * The period, where the subject has one.
         *
         * A dataset that answers null to `periodColumn()` is a subject a period cannot bound — an employee is
         * a state rather than an event, and `payslips.month` holds a month *name*. Item 5 offers "a mandatory
         * period filter **or** an explicit row cap" and those subjects take the second branch, which is
         * `MAX_ROWS` and not a silent absence: the note says "every row" so nobody reads a headcount as this
         * month's joiners.
         */
        if (($period = $class::periodColumn()) !== null) {
            $this->between($query, $period, $range);
        }

        foreach ($state['filters'] as $key => $value) {
            $filter = $class::filter($key);

            if ($filter !== null) {
                $this->filter($query, $filter, $value, $asOf);
            }
        }

        return $query;
    }

    /**
     * A date column bounded by two dates, through a relation where the column names one.
     *
     * **`>= from` and `< the morning after`, rather than `whereBetween`.** Half these columns are dates and
     * half are datetimes, and `between '2026-07-01' and '2026-07-31'` silently excludes everything that
     * happened *during* 31 July on the datetime half. This form is right for both and still uses the index,
     * which `whereDate()` on either would not.
     *
     * @param  array{from: string, to: string}  $range
     */
    private function between(Builder $query, string $column, array $range): void
    {
        $after = Carbon::parse($range['to'])->addDay()->toDateString();

        // `relation.column` — a journal line has no date of its own, its entry does. A subquery rather than a
        // join, because the relation is one the model declares and a join is SQL this class does not write.
        if (str_contains($column, '.')) {
            [$relation, $local] = explode('.', $column, 2);

            $query->whereHas($relation, function ($related) use ($local, $range, $after): void {
                $related->where($local, '>=', $range['from'])->where($local, '<', $after);
            });

            return;
        }

        $query->where($column, '>=', $range['from'])->where($column, '<', $after);
    }

    /** One stored filter, as the kind it was declared as. */
    private function filter(Builder $query, DatasetFilter $filter, mixed $value, string $asOf): void
    {
        match ($filter->kind) {
            DatasetFilter::DATE_RANGE => $this->between(
                $query,
                (string) $filter->column,
                // Relative here too, because a definition may not hold a date — see
                // `ReportDefinition::filterValue()`. Resolved against the same day as the period, so a
                // report cannot be looking at two different "now"s at once.
                RelativePeriod::range(is_string($value) ? $value : null, $asOf),
            ),
            DatasetFilter::FLAG => $query->where(
                (string) $filter->column,
                filter_var($value, FILTER_VALIDATE_BOOLEAN),
            ),
            DatasetFilter::SEARCH => $query->where(function (Builder $search) use ($filter, $value): void {
                foreach ($filter->columns as $column) {
                    $search->orWhere($column, 'like', '%'.((string) $value).'%');
                }
            }),
            // A select is an equality on a declared column: the value is a binding, and the column came from
            // the dataset rather than from the request. Neither half is anything a reader wrote.
            default => $query->where((string) $filter->column, $value),
        };
    }

    // ─────────────────────────────────────────────────────────────── the listing

    /**
     * A row per record, in the columns the definition names.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     * @return array<string, mixed>
     */
    private function listing(ReportDefinition $definition, string $class, array $state, array $range, string $asOf, Builder $query): array
    {
        $columns = $this->declared($class, $state['columns']);

        if ($columns === []) {
            return $this->unbuilt($definition, $class, $state, $range, $asOf);
        }

        $rows = $this->ordered($query, $class, $state)
            ->with($this->eagerLoads($columns))
            // One more than the ceiling, which is how the ceiling is *detected* rather than assumed: a
            // `count()` first would be a second query over the same rows to answer a question the limit
            // already answers.
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($rows->count() > self::MAX_ROWS) {
            return $this->refusal($definition, $class, $state, $range, $asOf, $columns);
        }

        $totals = $this->totals($columns, $rows);

        return $this->payload(
            definition: $definition,
            class: $class,
            state: $state,
            range: $range,
            asOf: $asOf,
            labels: array_map(fn (DatasetColumn $column): string => $column->label, $columns),
            types: array_map(fn (DatasetColumn $column): string => $column->type, $columns),
            rows: $rows->map(fn (Model $row): array => array_map(
                fn (DatasetColumn $column): string => $this->cell($column, $row),
                $columns,
            ))->all(),
            footer: $this->footer($rows->count(), 'row', $columns, $totals),
            tiles: $this->tiles($rows->count(), 'ROWS', $columns, $totals),
            count: $rows->count(),
            unit: 'row',
        );
    }

    /**
     * The declared columns behind a definition's column keys.
     *
     * Every key is one the dataset offers, because `ReportDefinition::settings()` sanitises on read as well
     * as on write. The filter is still here for the row that outlives a column: a definition naming one since
     * removed loses that column and keeps the rest.
     *
     * @param  array<int, string>  $keys
     * @return array<int, DatasetColumn>
     */
    private function declared(string $class, array $keys): array
    {
        return array_values(array_filter(array_map(
            fn (string $key): ?DatasetColumn => $class::column($key),
            $keys,
        )));
    }

    /**
     * The relations to load with the rows.
     *
     * A related column reads through a relation, and reading it per row is the N+1 this plan's own risk list
     * names. The path without its attribute — `contact.name` is loaded as `contact`.
     *
     * @param  array<int, DatasetColumn>  $columns
     * @return array<int, string>
     */
    private function eagerLoads(array $columns): array
    {
        $relations = [];

        foreach ($columns as $column) {
            if ($column->relation === null) {
                continue;
            }

            $path = explode('.', $column->relation);
            array_pop($path);

            if ($path !== []) {
                $relations[implode('.', $path)] = true;
            }
        }

        return array_keys($relations);
    }

    /**
     * The order rows come back in.
     *
     * **A sort names a real column or is ignored.** A related column would need the join this builder does
     * not write and a derived one is a closure the database cannot see — so the stored sort is applied where
     * it can be and the period's own column stands in where it cannot, newest first, which is how a report
     * over a span is read.
     *
     * The key is always the last word, so the thousand rows a capped report *would* have shown are the same
     * thousand every time. An unordered `limit` is a different report on every request.
     *
     * @param  array<string, mixed>  $state
     */
    private function ordered(Builder $query, string $class, array $state): Builder
    {
        $sort = $state['sort'];
        $column = $sort === null ? null : $class::column($sort['column']);

        if ($column !== null && $column->select !== null) {
            $query->orderBy($column->select, $sort['direction']);
        } elseif (($period = $class::periodColumn()) !== null && ! str_contains($period, '.')) {
            $query->orderByDesc($period);
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    // ─────────────────────────────────────────────────────────────── the grouping

    /**
     * A row per bucket, with the aggregates the definition asks for.
     *
     * Aggregated in SQL, which item 5 requires and Phase 6.1 made structural: only a real column of this
     * table can carry `sum`, `avg`, `min` or `max`, because `DatasetColumn::derived()` offers nothing but
     * `count`. So there is no path here that groups rows in PHP.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     * @return array<string, mixed>
     */
    private function grouped(ReportDefinition $definition, string $class, array $state, array $range, string $asOf, Builder $query): array
    {
        $group = $class::column((string) $state['group_by']);

        if ($group === null || ! $group->isGroupable()) {
            return $this->unbuilt($definition, $class, $state, $range, $asOf);
        }

        $grammar = $query->getQuery()->getGrammar();
        $specs = $this->aggregates($class, $state['aggregates'], $grammar);

        /*
         * The one raw fragment in this class, and what makes it safe is that neither half of it comes from a
         * request: the column is the dataset's own declaration, quoted by the connection's own grammar, and
         * the function is one of five words in `AGGREGATE_WORDS`. There is no Eloquent form of
         * "select sum(x) as y group by z" that avoids it, and pulling the rows back to add them up in PHP is
         * the thing item 5 forbids.
         */
        $selects = [$grammar->wrap((string) $group->groupBy).' as report_group'];

        foreach ($specs as $spec) {
            $selects[] = $spec['expression'].' as '.$spec['alias'];
        }

        $buckets = (clone $query)
            ->selectRaw(implode(', ', $selects))
            ->groupBy((string) $group->groupBy)
            ->orderBy((string) $group->groupBy, $this->groupDirection($state, $group))
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($buckets->count() > self::MAX_ROWS) {
            return $this->refusal($definition, $class, $state, $range, $asOf, [$group], $specs);
        }

        $labels = $this->groupLabels($class, $group, $buckets->pluck('report_group')->all());

        $rows = $buckets->map(function (Model $bucket) use ($group, $labels, $specs): array {
            $key = $bucket->getAttribute('report_group');

            $row = [$this->groupCell($group, $key, $labels)];

            foreach ($specs as $spec) {
                $row[] = $this->figure($bucket->getAttribute($spec['alias']), $spec['type']);
            }

            return $row;
        })->all();

        $totals = [0 => null];

        foreach ($specs as $index => $spec) {
            $totals[$index + 1] = in_array($spec['aggregate'], self::ADDITIVE, true)
                ? (float) $buckets->sum(fn (Model $bucket): float => (float) $bucket->getAttribute($spec['alias']))
                : null;
        }

        $columns = [$group, ...array_column($specs, 'column')];
        $types = [$group->type, ...array_column($specs, 'type')];

        return $this->payload(
            definition: $definition,
            class: $class,
            state: $state,
            range: $range,
            asOf: $asOf,
            labels: [$group->label, ...array_column($specs, 'label')],
            types: $types,
            rows: $rows,
            footer: $this->footer($buckets->count(), 'group', $columns, $totals, $types),
            tiles: $this->tiles($buckets->count(), 'GROUPS', $columns, $totals, $types),
            count: $buckets->count(),
            unit: 'group',
        );
    }

    /**
     * One aggregate per column the definition asks for, as SQL and as a header.
     *
     * `count` is the only aggregate a related or derived column offers, and it becomes `count(*)` for the
     * ones with no column of their own to count — which is the same answer for a column that is never null
     * and the only answer available for a closure.
     *
     * @param  array<string, string>  $aggregates
     * @return array<int, array{column: DatasetColumn, aggregate: string, alias: string, expression: string, label: string, type: string}>
     */
    private function aggregates(string $class, array $aggregates, Grammar $grammar): array
    {
        $specs = [];

        foreach ($aggregates as $key => $aggregate) {
            $column = $class::column($key);

            if ($column === null || ! $column->canAggregate($aggregate)) {
                continue;
            }

            if ($column->select === null && $aggregate !== DatasetColumn::COUNT) {
                continue;
            }

            $index = count($specs);

            $specs[] = [
                'column' => $column,
                'aggregate' => $aggregate,
                'alias' => 'report_aggregate_'.$index,
                'expression' => $this->expression($column, $aggregate, $grammar),
                'label' => $column->label.' ('.self::AGGREGATE_WORDS[$aggregate].')',
                // A count is a number whatever it counts, and everything else keeps the column's own type so
                // an average of a money column reads as money.
                'type' => $aggregate === DatasetColumn::COUNT ? DatasetColumn::NUMBER : $column->type,
            ];
        }

        return $specs;
    }

    /** `sum("total")`, from a declared column and one of five words. */
    private function expression(DatasetColumn $column, string $aggregate, Grammar $grammar): string
    {
        return $column->select === null
            ? 'count(*)'
            : $aggregate.'('.$grammar->wrap($column->select).')';
    }

    /** Descending only where the definition sorts on the group column itself and says so. */
    private function groupDirection(array $state, DatasetColumn $group): string
    {
        $sort = $state['sort'];

        return $sort !== null && $sort['column'] === $group->key
            ? $sort['direction']
            : ReportDefinition::ASCENDING;
    }

    /**
     * What each bucket is called, where the buckets are foreign keys.
     *
     * Phase 6.1's reasoning, made visible: "the database buckets on `contact_id` and the pane prints the
     * contact's name, which is the same report without a join". The names come from the related model's own
     * query, so its tenancy and global scopes apply — one statement for the whole column.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int|string, string>
     */
    private function groupLabels(string $class, DatasetColumn $column, array $keys): array
    {
        if ($column->relation === null) {
            return [];
        }

        $path = explode('.', $column->relation);
        $keys = array_values(array_filter($keys, fn (mixed $key): bool => $key !== null && $key !== ''));

        // One hop only. A two-hop relation would need the intermediate query as well, and no dataset
        // declares a groupable column through one — a report that needs it is the coded report item 7 names.
        if (count($path) !== 2 || $keys === []) {
            return [];
        }

        [$relation, $attribute] = $path;
        $model = new ($class::model());

        if (! method_exists($model, $relation)) {
            return [];
        }

        $related = $model->{$relation}()->getRelated();

        return $related->newQuery()
            ->whereIn($related->getKeyName(), $keys)
            ->pluck($attribute, $related->getKeyName())
            ->map(fn (mixed $label): string => (string) $label)
            ->all();
    }

    /**
     * A bucket's label.
     *
     * A blank bucket is a real answer — invoices with no project, tickets with no assignee — and it is the
     * one every grouped report has. Named rather than left empty, because an empty first cell reads as a
     * rendering fault.
     *
     * @param  array<int|string, string>  $labels
     */
    private function groupCell(DatasetColumn $column, mixed $key, array $labels): string
    {
        if ($key === null || $key === '') {
            return 'None';
        }

        if ($column->relation !== null) {
            return $labels[$key] ?? ('#'.$key);
        }

        return $column->type === DatasetColumn::DATE
            ? Carbon::parse($key)->format('j M Y')
            : (string) $key;
    }

    // ─────────────────────────────────────────────────────────────────── the shape

    /**
     * The payload every path here returns.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $types
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>|null  $footer
     * @param  array<int, array<string, mixed>>  $tiles
     * @return array<string, mixed>
     */
    private function payload(
        ReportDefinition $definition,
        string $class,
        array $state,
        array $range,
        string $asOf,
        array $labels,
        array $types,
        array $rows,
        ?array $footer,
        array $tiles,
        int $count,
        string $unit,
        ?string $empty = null,
        ?string $note = null,
        bool $balanced = true,
    ): array {
        return [
            ...$this->table(
                $definition->reportKey(),
                $definition->name,
                $this->subtitle($this->periodText($class, $state, $range, $asOf)),
                $labels,
                $this->grid($types),
                $this->numeric($types),
                $rows,
                $tiles,
                $note ?? $this->note($class, $state, $range, $asOf, $count, $unit),
                $footer,
                $empty ?? 'Nothing matches this report for this period.',
                // Decided from the column count, because nobody has seen this report before it is drawn.
                wide: count($labels) >= self::WIDE_AT,
            ),
            // `balanced` is false only for a refusal: the pane draws the note in warning colour, which is how
            // a report says something about itself that its rows cannot.
            'balanced' => $balanced,
        ];
    }

    /**
     * The refusal — item 5, and the sentence the plan asks for.
     *
     * The columns are still declared, so what the reader sees is the report they asked for with an
     * explanation where the rows would be, rather than an error page or an empty table that reads as
     * "nothing happened".
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     * @param  array<int, DatasetColumn>  $columns
     * @param  array<int, array<string, mixed>>  $specs
     * @return array<string, mixed>
     */
    private function refusal(ReportDefinition $definition, string $class, array $state, array $range, string $asOf, array $columns, array $specs = []): array
    {
        $labels = $specs === []
            ? array_map(fn (DatasetColumn $column): string => $column->label, $columns)
            : [$columns[0]->label, ...array_column($specs, 'label')];

        $types = $specs === []
            ? array_map(fn (DatasetColumn $column): string => $column->type, $columns)
            : [$columns[0]->type, ...array_column($specs, 'type')];

        $ceiling = number_format(self::MAX_ROWS);

        return $this->payload(
            definition: $definition,
            class: $class,
            state: $state,
            range: $range,
            asOf: $asOf,
            labels: $labels,
            types: $types,
            rows: [],
            footer: null,
            tiles: [],
            count: 0,
            unit: 'row',
            empty: 'This report asks for more than '.$ceiling.' rows. Narrow the period, or add a filter, and it will draw.',
            note: mb_strtoupper('more than '.$ceiling.' rows · narrow the period or add a filter'),
            balanced: false,
        );
    }

    /**
     * A definition with nothing to draw yet.
     *
     * Its own state rather than an empty table, because the two have different answers: a report with no
     * columns is unfinished and a report with no rows is finished and empty, and telling somebody "nothing
     * matches" about a report that asks for nothing sends them looking for data that is already there.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     * @return array<string, mixed>
     */
    private function unbuilt(ReportDefinition $definition, string $class, array $state, array $range, string $asOf): array
    {
        return $this->payload(
            definition: $definition,
            class: $class,
            state: $state,
            range: $range,
            asOf: $asOf,
            labels: [],
            types: [],
            rows: [],
            footer: null,
            tiles: [],
            count: 0,
            unit: 'row',
            empty: 'This report has no columns yet. Open it in the builder and choose what it should show.',
            note: 'NOTHING CHOSEN YET',
            balanced: false,
        );
    }

    /**
     * The record row: what it counted, and a total under every column that can carry one.
     *
     * @param  array<int, DatasetColumn>  $columns
     * @param  array<int, float|null>  $totals
     * @param  array<int, string>|null  $types
     * @return array<int, string>|null
     */
    private function footer(int $count, string $unit, array $columns, array $totals, ?array $types = null): ?array
    {
        if ($count === 0 || $columns === []) {
            return null;
        }

        $footer = ['Total — '.number_format($count).' '.($count === 1 ? $unit : $unit.'s')];

        for ($index = 1; $index < count($totals); $index++) {
            $footer[] = $totals[$index] === null
                ? ''
                : $this->figure($totals[$index], $types[$index] ?? $columns[$index]->type);
        }

        return $footer;
    }

    /**
     * Column totals, as figures rather than strings, for the footer and the tiles at once.
     *
     * Null where a column cannot be totalled, which is every derived and every related column — see the
     * class docblock: this is Phase 6.1's refusal, and computing it here in PHP is how it would be undone.
     *
     * @param  array<int, DatasetColumn>  $columns
     * @param  EloquentCollection<int, Model>  $rows
     * @return array<int, float|null>
     */
    private function totals(array $columns, EloquentCollection $rows): array
    {
        $totals = [];

        foreach ($columns as $index => $column) {
            $totals[$index] = $column->select !== null && $column->isNumeric()
                ? (float) $rows->sum(fn (Model $row): float => (float) $column->value($row))
                : null;
        }

        return $totals;
    }

    /**
     * The strip across the top: how many rows, and the first figure worth leading with.
     *
     * One accent tile at most. The record row already carries every total; a tile per money column would be
     * the same numbers twice, and the tiles are meant to be the thing somebody reads first.
     *
     * @param  array<int, DatasetColumn>  $columns
     * @param  array<int, float|null>  $totals
     * @param  array<int, string>|null  $types
     * @return array<int, array<string, mixed>>
     */
    private function tiles(int $count, string $label, array $columns, array $totals, ?array $types = null): array
    {
        $tiles = [['label' => $label, 'value' => (float) $count, 'accent' => false]];

        foreach ($columns as $index => $column) {
            $type = $types[$index] ?? $column->type;

            if (($totals[$index] ?? null) !== null && $type === DatasetColumn::MONEY) {
                $tiles[] = [
                    'label' => mb_strtoupper($column->label),
                    'value' => $totals[$index],
                    'accent' => true,
                ];

                break;
            }
        }

        return $tiles;
    }

    /**
     * The grid, from the column types.
     *
     * Figures and dates get a fixed width and text takes what is left, which is what every coded report
     * declares by hand. At least one column has to be flexible or the table sits in the left of the pane
     * with a gap beside it, so the first one is widened where nothing else is.
     *
     * @param  array<int, string>  $types
     */
    private function grid(array $types): string
    {
        if ($types === []) {
            return 'minmax(0, 1fr)';
        }

        $widths = array_map(fn (string $type): string => match ($type) {
            DatasetColumn::MONEY, DatasetColumn::NUMBER => '9rem',
            DatasetColumn::DATE => '8rem',
            DatasetColumn::BOOLEAN => '6rem',
            default => 'minmax(8rem, 1fr)',
        }, $types);

        if (! str_contains(implode(' ', $widths), 'fr')) {
            $widths[0] = 'minmax(8rem, 1fr)';
        }

        return implode(' ', $widths);
    }

    /**
     * Which columns are figures, and therefore right-aligned.
     *
     * @param  array<int, string>  $types
     * @return array<int, int>
     */
    private function numeric(array $types): array
    {
        return array_values(array_keys(array_filter(
            $types,
            fn (string $type): bool => in_array($type, [DatasetColumn::MONEY, DatasetColumn::NUMBER], true),
        )));
    }

    /** One cell, formatted by the type the dataset declared for it. */
    private function cell(DatasetColumn $column, Model $row): string
    {
        $value = $column->value($row);

        if ($value === null || $value === '') {
            return '—';
        }

        return match ($column->type) {
            DatasetColumn::MONEY, DatasetColumn::NUMBER => $this->figure($value, $column->type),
            DatasetColumn::DATE => Carbon::parse($value)->format('j M Y'),
            DatasetColumn::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    /**
     * A figure, through `ReportFigures` so a company that reads `(1,250)` reads it here too — Phase 4.3.
     *
     * Money is whole units, as every report in this application states it. A plain number keeps two decimals
     * only when it has any, because a quantity of 12.5 rounded to 13 is a wrong figure while a count of 12
     * printed as 12.00 is only a noisy one.
     */
    private function figure(mixed $value, string $type): string
    {
        $number = (float) $value;

        $decimals = $type === DatasetColumn::MONEY || $number === floor($number) ? 0 : 2;

        return ReportFigures::money($number, $decimals);
    }

    /**
     * What period this report covers, for the subtitle.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     */
    private function periodText(string $class, array $state, array $range, string $asOf): string
    {
        if ($class::periodColumn() === null) {
            return 'every row · as at '.Carbon::parse($asOf)->format('j M Y');
        }

        return Carbon::parse($range['from'])->format('j M Y').' to '.Carbon::parse($range['to'])->format('j M Y');
    }

    /**
     * The sentence under the tiles: the span, what is filtered, and how much came back.
     *
     * The filters are named because a report whose filters are invisible is a report nobody can check — "is
     * this every invoice or only the sales?" is the first question anybody asks of a figure. Values are
     * printed rather than resolved: naming a contact would mean loading the filter's whole option list, every
     * contact in the company, to print one word.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, to: string}  $range
     */
    private function note(string $class, array $state, array $range, string $asOf, int $count, string $unit): string
    {
        $parts = [$class::periodColumn() === null ? 'every row' : RelativePeriod::label($state['period'])];

        foreach ($state['filters'] as $key => $value) {
            $filter = $class::filter($key);

            if ($filter !== null) {
                $parts[] = $filter->label.': '.$this->filterText($filter, $value);
            }
        }

        $parts[] = number_format($count).' '.($count === 1 ? $unit : $unit.'s');

        return mb_strtoupper(implode(' · ', $parts));
    }

    /** How a filter's own value reads in the note. */
    private function filterText(DatasetFilter $filter, mixed $value): string
    {
        return match ($filter->kind) {
            DatasetFilter::DATE_RANGE => RelativePeriod::label(is_string($value) ? $value : null),
            DatasetFilter::FLAG => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no',
            default => str_ends_with((string) $filter->column, '_id') ? '#'.$value : (string) $value,
        };
    }
}
