<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Support\ModuleMap;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetRegistry;
use App\Support\Reporting\RelativePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * A report somebody assembled — `docs/reports-expansion-plan.md` Phase 6, items 3 and 6.
 *
 * **The state is sanitised against the dataset, on write and on read.** Every key a definition holds is a
 * *declared* key of its subject: a column the dataset offers, a filter it offers, an aggregate that column
 * allows, one of six relative periods. Anything else is dropped. Which is the same discipline
 * `SavedReportView::filtered()` applies to a saved view, one level up — a definition is a query, so the set of
 * definitions that can exist has to be the set of queries the registry can answer.
 *
 * Sanitised on read as well, because a row outlives the code that wrote it: a definition naming a column that
 * has since been removed from its dataset must lose that column rather than hand it to a query builder.
 *
 * **The sharp end of item 6 is `dataset()`, not `is_public`.** The plan says "a company-wide custom report over
 * payslips is a payroll leak, and it is one careless toggle away". Two things answer that, and the second is
 * the one that makes the toggle safe:
 *
 *  - setting `is_public` needs `ReportShare`, which only an administrator holds;
 *  - **reading a definition resolves its dataset through `DatasetRegistry::find()`**, which gates on the
 *    *reader's* module licence and the *reader's* permission. So a payslip report shared with the company
 *    resolves to nothing for everybody who cannot open a payslip, and to their own downline's rows for
 *    everybody who can — because Phase 6.1's `access()` is on the dataset's base query.
 *
 * The toggle therefore cannot reveal what the reader could not already open, which is a stronger guarantee
 * than being careful with the toggle.
 *
 * **No policy of its own yet.** Nothing renders a definition until item 4 and nothing edits one until item 7,
 * so the gates that exist are the ones above; a `ReportDefinitionPolicy` with no resource behind it would be a
 * file asserting permissions nothing checks. `ModuleCoverageTest` only requires a policy of a model that backs
 * a Filament resource, which this does not.
 */
class ReportDefinition extends Model
{
    /**
     * What a definition's state may hold, and nothing else.
     *
     * An allow-list for the reason `SavedReportView::FILTERS` is one: a future property of the builder should
     * not silently become part of everybody's saved reports.
     *
     * @var array<int, string>
     */
    public const KEYS = ['columns', 'filters', 'group_by', 'aggregates', 'sort', 'period'];

    public const ASCENDING = 'asc';

    public const DESCENDING = 'desc';

    /** The permission to build one at all. `ReportView` still governs reading — see item 6. */
    public const BUILD = 'ReportBuild';

    /** And to share one with the company, which is the toggle item 6 is about. */
    public const SHARE = 'ReportShare';

    protected $fillable = ['user_id', 'name', 'description', 'dataset', 'state', 'is_public'];

    protected $casts = [
        'state' => 'array',
        'is_public' => 'boolean',
    ];

    /**
     * Normalise on write: `dataset` holds a dataset's stable alias, never its live class name.
     *
     * The same mutator `TableView::setResourceAttribute()` has, for the same reason — a class that moves must
     * not orphan every report saved over it. Callers may hand over `PayslipDataset::class` directly.
     */
    public function setDatasetAttribute(?string $value): void
    {
        $this->attributes['dataset'] = $value === null ? null : ModuleMap::alias($value);
    }

    /**
     * The subject this reports on, if the reader may report on it.
     *
     * Through `DatasetRegistry::find()`, which is the gate described in the class docblock: null for a
     * definition over a module this company has switched off, or a subject this person's role cannot open.
     * Every caller has to handle null anyway, for a definition naming a dataset that has since been deleted.
     *
     * @return class-string<Dataset>|null
     */
    public function dataset(): ?string
    {
        return DatasetRegistry::find($this->dataset);
    }

    /**
     * The definitions this person may read: their own, plus whatever has been shared.
     *
     * Ordered by name rather than by id, because this list is read as a directory — Phase 4's hub sorts every
     * section's reports the same way, and a custom section that shuffled as people saved would be the one
     * place in the hub you cannot find anything twice.
     */
    public function scopeVisibleTo(Builder $query, ?int $userId = null): Builder
    {
        $userId ??= auth()->id();

        return $query
            ->where(function (Builder $query) use ($userId): void {
                $query->where('is_public', true);

                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->orderBy('name');
    }

    /** Strictly this person's own. */
    public function scopeMine(Builder $query, ?int $userId = null): Builder
    {
        return $query->where('user_id', $userId ?? auth()->id())->orderBy('name');
    }

    /**
     * The definitions this person may read *and* whose subject they may report on.
     *
     * The list anything user-facing should use. Filtered in PHP rather than in SQL because availability is a
     * question about modules and permissions, not about rows — and the alternative, storing which subjects a
     * definition needs, would be a copy of the registry in the database.
     *
     * @return EloquentCollection<int, self>
     */
    public static function readable(?int $userId = null): EloquentCollection
    {
        return static::query()
            ->visibleTo($userId)
            ->get()
            ->filter(fn (self $definition): bool => $definition->dataset() !== null)
            ->values();
    }

    /**
     * Save a definition under a name, replacing one of that name.
     *
     * **Sharing is refused rather than silently dropped when the permission is absent**, which is the
     * difference between a guard and a nuisance: somebody without `ReportShare` who ticks the box should be
     * told, and the caller is the only layer that can tell them. So this returns null for that case, and item
     * 7's form checks the permission before offering the toggle at all.
     *
     * @param  array<string, mixed>  $state
     */
    public static function put(string $name, string $dataset, array $state, ?string $description = null, bool $isPublic = false): ?self
    {
        $userId = auth()->id();

        if ($userId === null || ! auth()->user()?->can(self::BUILD)) {
            return null;
        }

        if ($isPublic && ! auth()->user()?->can(self::SHARE)) {
            return null;
        }

        $class = DatasetRegistry::find(ModuleMap::alias($dataset));

        // A definition over a subject this person cannot report on is not a definition, it is a way in.
        if ($class === null) {
            return null;
        }

        return static::query()->updateOrCreate(
            ['user_id' => $userId, 'name' => trim($name)],
            [
                'dataset' => $dataset,
                'description' => $description,
                'is_public' => $isPublic,
                'state' => static::sanitise($state, $class),
            ],
        );
    }

    /**
     * The state to render, re-sanitised on the way out.
     *
     * @return array{columns: array<int, string>, filters: array<string, mixed>, group_by: ?string, aggregates: array<string, string>, sort: ?array{column: string, direction: string}, period: string}
     */
    public function settings(): array
    {
        $class = $this->dataset();

        return $class === null
            ? static::empty()
            : static::sanitise((array) $this->state, $class);
    }

    /** The span this report covers, resolved against a day — relative, never stored as dates. */
    public function range(?string $on = null): array
    {
        return RelativePeriod::range($this->settings()['period'], $on);
    }

    /**
     * Only what the dataset declares.
     *
     * Six rules, one per key, and each drops rather than corrects — a definition naming a column that no
     * longer exists loses that column and keeps the rest, which is the behaviour that lets a dataset evolve
     * without breaking every report over it.
     *
     * @param  array<string, mixed>  $state
     * @param  class-string<Dataset>  $class
     * @return array{columns: array<int, string>, filters: array<string, mixed>, group_by: ?string, aggregates: array<string, string>, sort: ?array{column: string, direction: string}, period: string}
     */
    public static function sanitise(array $state, string $class): array
    {
        // Columns, in the order given, without repeats: the order *is* the report's shape.
        $columns = [];

        foreach ((array) ($state['columns'] ?? []) as $key) {
            if (is_string($key) && $class::column($key) !== null && ! in_array($key, $columns, true)) {
                $columns[] = $key;
            }
        }

        // Filters the dataset offers, with a value. A blank value is the absence of a filter rather than a
        // filter for blank — `SavedReportView::filtered()` takes the same position.
        $filters = [];

        foreach ((array) ($state['filters'] ?? []) as $key => $value) {
            if (is_string($key) && $class::filter($key) !== null && $value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        // A group-by must be a column the *database* can group on — see `DatasetColumn::isGroupable()`, which
        // is false for anything derived or reached through a relation without a local key.
        $groupBy = $state['group_by'] ?? null;
        $groupBy = is_string($groupBy) && $class::column($groupBy)?->isGroupable() ? $groupBy : null;

        // An aggregate the column itself allows: no summing a status, no summing anything derived.
        $aggregates = [];

        foreach ((array) ($state['aggregates'] ?? []) as $key => $aggregate) {
            if (is_string($key) && is_string($aggregate) && $class::column($key)?->canAggregate($aggregate)) {
                $aggregates[$key] = $aggregate;
            }
        }

        return [
            'columns' => $columns,
            'filters' => $filters,
            'group_by' => $groupBy,
            'aggregates' => $aggregates,
            'sort' => static::sort($state['sort'] ?? null, $class),
            'period' => RelativePeriod::normalise(is_string($state['period'] ?? null) ? $state['period'] : null),
        ];
    }

    /**
     * The sort, or none.
     *
     * A direction is one of two words rather than "whatever was stored", because this reaches an `orderBy`.
     * Nothing else in the state touches SQL structure so directly, and `orderBy` is the one place Eloquent
     * will pass a string through.
     *
     * @param  class-string<Dataset>  $class
     * @return array{column: string, direction: string}|null
     */
    private static function sort(mixed $sort, string $class): ?array
    {
        if (! is_array($sort) || ! is_string($sort['column'] ?? null)) {
            return null;
        }

        if ($class::column($sort['column']) === null) {
            return null;
        }

        return [
            'column' => $sort['column'],
            'direction' => ($sort['direction'] ?? null) === self::DESCENDING ? self::DESCENDING : self::ASCENDING,
        ];
    }

    /**
     * A state that renders nothing.
     *
     * What `settings()` answers for a definition whose subject the reader may not report on — a shape every
     * caller can read without branching, rather than a null they have to remember to check.
     *
     * @return array{columns: array<int, string>, filters: array<string, mixed>, group_by: ?string, aggregates: array<string, string>, sort: ?array{column: string, direction: string}, period: string}
     */
    public static function empty(): array
    {
        return [
            'columns' => [],
            'filters' => [],
            'group_by' => null,
            'aggregates' => [],
            'sort' => null,
            'period' => RelativePeriod::normalise(null),
        ];
    }
}
