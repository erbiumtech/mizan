<?php

namespace App\Support\Reporting;

/**
 * What the Reports explorer needs in order to draw a report in its right-hand pane.
 *
 * `Core\Filament\Pages\Reports` called `Accounting\Support\ReportPane` directly — the last of §9's
 * `core -> accounting` imports, and the one that could not be solved by moving a list, because the pane is
 * behaviour rather than data. So it is named as a contract instead: Accounting binds its pane, and the
 * explorer asks whoever is bound.
 *
 * **Instance methods, including the two that were static.** `supports()` and `asks()` were `ReportPane`
 * statics, which cannot be resolved through the container and so cannot be swapped. They stay as statics on
 * ReportPane — dozens of call sites and every test use them — with instance methods added alongside for
 * this contract. That duplication is deliberate and cheap; the alternative was rewriting call sites to
 * prove a point about indirection.
 *
 * `NoReportPane` is the default, for a company with no accounting module: it supports nothing, so the
 * explorer lists whatever reports are registered and offers each one's own page instead of a pane — which
 * is exactly what it already does for a report the pane cannot draw.
 */
interface ReportPaneRenderer
{
    /** Can this report be drawn in the pane at all? */
    public function supportsReport(?string $key): bool;

    /**
     * The filters this report carries beyond the date.
     *
     * @return array<int, string>
     */
    public function asksFor(?string $key): array;

    /**
     * The report, drawn — or null when it needs input the pane has not been given.
     *
     * @param  array<string, mixed>  $asked
     * @return array<string, mixed>|null
     */
    /**
     * `$comparison` is a `ReportComparison` basis. A bool is still accepted and still means what it always
     * did — `true` the previous year, `false` none — so every link somebody kept still lands where it did.
     */
    public function for(string $key, string $asOf, bool|string $comparison = true, array $asked = []): ?array;

    /**
     * The options behind one of a report's pickers.
     *
     * @return array<int|string, string>
     */
    public function options(string $key, ?string $ask = null, ?string $asOf = null): array;

    /**
     * The account codes a statement line may drill into.
     *
     * @return array<int, string>
     */
    public function drillable(): array;

    /**
     * The account a drillable code identifies, as the register pane wants it — or null if that code cannot
     * be drilled into.
     *
     * Asked rather than looked up, because the explorer has no way to turn a code into an account without
     * naming an accounting model, and that lookup was the last `core -> accounting` reference in the whole
     * module. It answers both halves at once on purpose: a code that is not in `drillable()` must not be
     * resolved, and keeping the check next to the query is what stops the two from disagreeing.
     */
    public function drillTarget(string $code): int|string|null;
}
