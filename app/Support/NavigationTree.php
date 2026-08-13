<?php

namespace App\Support;

use Filament\Navigation\NavigationItem;
use UnitEnum;

/**
 * The third level: splitting the long groups into branches that open and close.
 *
 * Two of these groups were unusable as flat lists — Employee had twenty entries and Accounting
 * twelve, which is a scroll rather than a menu. This breaks them into named branches, so the People
 * column reads "Employees / Payroll / Leave / Attendance & time / Lifecycle / Projects" and you open
 * the one you want.
 *
 * **Branches are groups, not nested items, and that is a deliberate choice.** Filament does support
 * parent/child navigation items, and it looks closer to a tree — but it has no collapse of its own:
 * `sidebar/item.blade.php` renders children when the branch is active or when the branch has no URL,
 * so a nested branch either springs open by itself or never closes. Groups are the level Filament
 * makes collapsible, remembers per person in localStorage, and marks active — which is what "open
 * and close" needs. The result is the same shape on screen with behaviour that actually works.
 *
 * **Mapped here rather than on the classes.** Every entry below is a resource or page that declares
 * its own `$navigationGroup`, and rewriting 63 of those would scatter this decision across 63 files
 * and leave no single place to read the hierarchy or change it. The items are re-grouped in
 * DomainNavigationManager as the navigation is assembled, so the classes keep declaring the group
 * they belong to — which is still what NavigationDomains maps to a rail domain, and still what
 * NavigationGroupsTest reads.
 *
 * The map has to stay exhaustive: an item in a mapped group that no branch claims keeps its original
 * group label, which no domain then owns, and it disappears from the menu. NavigationTreeTest asserts
 * that in both directions.
 */
class NavigationTree
{
    /**
     * Declared group => branch => the items in it, in the order they should appear.
     *
     * A group absent from this list is left alone: Hiring, Performance, Access Control, Audit & Taxes
     * and Support are three or four entries each, and a branch holding two things is a click in front
     * of a list rather than a shorter one.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const TREE = [
        'Employee' => [
            'Employees' => [
                'Employees',
                'Employee Change Requests',
                'Employee Settings',
            ],
            'Payroll' => [
                'Payroll Months',
                'Payslips',
                'Advances',
                'Expense Claims',
                'Final Settlements',
            ],
            'Leave' => [
                'Leave Requests',
                'Leave Types',
                'Leave Balances',
            ],
            'Attendance & time' => [
                'Attendance',
                'Attendance Corrections',
                'Work Patterns',
                'Timesheets',
            ],
            // Joining and leaving: the documents, the kit and the checklist that tracks both.
            'Lifecycle' => [
                'Checklist Templates',
                'Employee Documents',
                'Issued Assets',
            ],
            // Both are about what people are working on rather than about their employment, and they
            // are the two entries in this group that a payroll clerk never opens.
            'Projects' => [
                'Projects',
                'MPR',
            ],
        ],
        'Accounting' => [
            'Ledger' => [
                'Chart Of Accounts',
                'Journal Entries',
                'Transaction Types',
                'Scheduled Entries',
                'Budgets',
            ],
            'Banking' => [
                'Banks',
                'Company Bank Accounts',
                'Bank Statements',
                'Beneficiaries',
                'Payments',
            ],
            'Assets & loans' => [
                'Fixed Assets',
                'Loans',
            ],
        ],
        // Invoicing & Inventory is deliberately absent. Its six entries were merged into one group by
        // an earlier decision this application documents in NavigationGroupsTest, and splitting them
        // back into "Invoicing" and "Inventory" would reinstate exactly the two labels that test
        // forbids. Six is not a scroll, so there is nothing here worth overruling that for.
        'Sales' => [
            // Campaigns sits with the pipeline rather than in a branch of its own: it is where leads
            // come from, and a branch holding one entry is not a branch.
            'Pipeline' => [
                'Leads',
                'Deals',
                'Next Actions',
                'Quotes',
                'Campaigns',
            ],
            'Sales setup' => [
                'Lead Sources',
                'Pipelines',
                'Sales Targets',
            ],
        ],
        'Settings' => [
            'Company' => [
                'Company Settings',
                'Modules',
                'Custom Fields',
                'Email Wording',
            ],
            'Payroll setup' => [
                'Pay Components',
                'Salary Slabs',
            ],
            'Calendar & currency' => [
                'Fiscal Years',
                'Holidays',
                'Currencies',
            ],
            'Imports' => [
                'Import from CSV',
                'GnuCash Import',
            ],
        ],
    ];

    /** Whether a declared group is split into branches. */
    public static function isMapped(string|UnitEnum|null $group): bool
    {
        return array_key_exists((string) self::label($group), self::TREE);
    }

    /**
     * What a declared group contributes to a column: its branches, or itself when it has none.
     *
     * This is what lets NavigationDomains go on owning the group labels the classes declare — a
     * domain claims "Employee" and gets its six branches, so adding a branch below needs no second
     * edit there.
     *
     * @return array<int, string>
     */
    public static function labelsFor(string|UnitEnum|null $group): array
    {
        $label = (string) self::label($group);

        return array_keys(self::TREE[$label] ?? []) ?: [$label];
    }

    /**
     * The declared group a branch came from — the reverse of labelsFor().
     *
     * A rendered column shows branches, and some questions are about the group underneath: which
     * groups this application organises its screens into is a decision recorded in
     * NavigationGroupsTest, and that decision is unchanged by how a column chooses to show them. This
     * is what lets that test go on asking about "Employee" while the column shows six branches.
     *
     * A label that is not a branch is its own answer, so this is safe to call on anything.
     */
    public static function declaredFor(string $label): string
    {
        foreach (self::TREE as $group => $branches) {
            if (array_key_exists($label, $branches)) {
                return $group;
            }
        }

        return $label;
    }

    /** The branch an item belongs in, or null when its group is not split. */
    public static function branchFor(string|UnitEnum|null $group, string $item): ?string
    {
        foreach (self::TREE[(string) self::label($group)] ?? [] as $branch => $items) {
            if (in_array($item, $items, true)) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * Every branch label, in the order the columns should show them.
     *
     * Passed to the panel's navigationGroups(), which is the only thing Filament sorts groups by —
     * without it the branches appear in whatever order their first item's sort happens to give,
     * which is the resources' own ordering and means nothing once they are regrouped.
     *
     * @return array<int, string>
     */
    public static function order(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::TREE)));
    }

    /**
     * Re-groups an item onto its branch. No-op for items this map says nothing about.
     *
     * The item's *sort* is rewritten too, to its position within the branch: sort is global across
     * the panel and was chosen to order each group as a flat list, so leaving it alone would order
     * the branch's contents by their old neighbours instead of by the list above.
     */
    public static function apply(NavigationItem $item): void
    {
        $branch = self::branchFor($item->getGroup(), $item->getLabel());

        if ($branch === null) {
            return;
        }

        $position = array_search(
            $item->getLabel(),
            self::TREE[(string) self::label($item->getGroup())][$branch],
            true,
        );

        $item->group($branch)->sort((int) $position);
    }

    /**
     * The items a branch claims, for the exhaustiveness test.
     *
     * @return array<int, string>
     */
    public static function itemsFor(string $group): array
    {
        return array_merge(...array_values(self::TREE[$group] ?? [[]]));
    }

    /** @return array<int, string> */
    public static function mappedGroups(): array
    {
        return array_keys(self::TREE);
    }

    private static function label(string|UnitEnum|null $group): ?string
    {
        if ($group instanceof UnitEnum) {
            return $group instanceof \Filament\Support\Contracts\HasLabel
                ? $group->getLabel()
                : $group->name;
        }

        return $group;
    }
}
