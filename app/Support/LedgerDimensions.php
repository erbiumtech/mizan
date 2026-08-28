<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * What a journal entry was *for*, derived from what produced it — `docs/erpnext-gap-plan.md` Phase 1.
 *
 * ERPNext puts a cost centre, a project and any number of declared dimensions on every ledger *line*.
 * This application has no such column and this registry is the argument for not adding one: a posting
 * that records the document it came from already knows every dimension that document knows. An invoice
 * knows its project and its customer; a payslip knows its employee and therefore their department; a
 * payment knows who it was paid to. **The source is the dimension, and every dimension at once.**
 *
 * `journal_entries` has carried `nullableMorphs('source')` since it was created. What it did not have was
 * anybody filling it in — 57% of a demo company's entries had no source, because only depreciation,
 * payroll, loans and the year-end closer ever passed one. Phase 1 is that column being populated and this
 * class reading it.
 *
 * **A registry, not a `match`, and the reason is the module boundary rather than taste.** The gap plan
 * proposed "one class, `match` on the alias, no interface and no registry until a third module needs to
 * extend it". A `match` naming Invoice, Payslip and Payment would have to live somewhere, and every
 * candidate is wrong: in Accounting it re-creates the `accounting -> invoicing` and `accounting -> payroll`
 * edges that `docs/module-packaging-plan.md` spent four registries removing, and in `App\Support` it
 * breaks the rule that shared code names no module. So the direction inverts, exactly as
 * `JournalEntryOwners`, `DashboardStats`, `ReportRenderers` and `PaymentGenerators` already do: each
 * module says how to read *its own* documents, from its own service provider, and a module that is not
 * installed contributes nothing and resolves to nothing.
 *
 * **Three dimensions and no more.** Project, party and department are what the documents in this
 * application actually know. A fourth is a closure away, and a fourth invented before somebody asks for
 * it is the "generic accounting dimensions" the gap plan's §5 refuses.
 */
class LedgerDimensions
{
    public const PROJECT = 'project';

    public const PARTY = 'party';

    public const DEPARTMENT = 'department';

    /** The dimensions a report may group by, and what to call them. */
    public const LABELS = [
        self::PROJECT => 'Project',
        self::DEPARTMENT => 'Department',
        self::PARTY => 'Party',
    ];

    /**
     * What an entry with no source, or a source that resolves to nothing, is called.
     *
     * Named rather than blank, and never folded into a total: an incomplete dimension that looks complete
     * is the one outcome worse than not having the dimension at all. A manual journal entry has no source
     * by construction — nothing produced it but a person — so this bucket is a permanent feature of the
     * design and not a backlog item.
     */
    public const UNASSIGNED = 'Unassigned';

    /**
     * Source alias => resolver.
     *
     * Keyed on `ModuleMap::alias()` because that is what `journal_entries.source_type` holds: a model may
     * move between directories and its saved postings may not stop resolving.
     *
     * @var array<string, \Closure(Model): array<string, string|null>>
     */
    private static array $resolvers = [];

    /**
     * Relations each resolver reads, so a report can load them before it asks.
     *
     * @var array<string, array<int, string>>
     */
    private static array $eagerLoads = [];

    /**
     * @param  class-string<Model>  $model
     * @param  \Closure(Model): array<string, string|null>  $resolver  returns any of project/party/department
     * @param  array<int, string>  $with  relations the resolver walks
     *
     * **`$with` is not an optimisation.** A resolver reads relations — an invoice's project, an employee's
     * department — and a report resolves hundreds of documents, so a relation the caller did not load is a
     * query per document. `preventLazyLoading` turns that into an exception in development rather than a
     * slow report in production, and the module that wrote the closure is the only one that knows what it
     * touches. So it declares both together, and neither can be updated without the other being obvious.
     */
    public static function register(string $model, \Closure $resolver, array $with = []): void
    {
        $alias = ModuleMap::alias($model);

        self::$resolvers[$alias] = $resolver;
        self::$eagerLoads[$alias] = $with;
    }

    /**
     * What to load with sources of this type before resolving them.
     *
     * @return array<int, string>
     */
    public static function eagerLoads(string $alias): array
    {
        return self::$eagerLoads[$alias] ?? [];
    }

    /** @return array<int, string> the source aliases something knows how to read */
    public static function sources(): array
    {
        return array_keys(self::$resolvers);
    }

    /**
     * The dimensions of one source record.
     *
     * Null for every dimension the source does not know, which is most of them for most sources: a stock
     * movement has no project and a fixed asset has no party. That is not a hole to fill in later — it is
     * the honest answer, and the report shows it as *Unassigned* rather than guessing.
     *
     * @return array{project: string|null, party: string|null, department: string|null}
     */
    public static function of(?Model $source): array
    {
        $empty = [self::PROJECT => null, self::PARTY => null, self::DEPARTMENT => null];

        if ($source === null) {
            return $empty;
        }

        $resolver = self::$resolvers[ModuleMap::alias($source::class)] ?? null;

        // A source whose module registered nothing resolves to nothing rather than throwing. The register
        // is the case that matters: a company with Inventory switched off still has last year's stock
        // postings in its ledger, and a report over them must draw rather than fail.
        return $resolver === null ? $empty : array_merge($empty, $resolver($source));
    }

    /**
     * One dimension of one source, as a bucket label.
     *
     * The bucket rather than the value, because that is what a report groups on: a source that knows the
     * dimension answers with it, and everything else lands in one named bucket together.
     */
    public static function bucket(?Model $source, string $dimension): string
    {
        $value = self::of($source)[$dimension] ?? null;

        return blank($value) ? self::UNASSIGNED : (string) $value;
    }

    /** For tests, and for the same reason the other registries have one. */
    public static function flush(): void
    {
        self::$resolvers = [];
        self::$eagerLoads = [];
    }
}
