<?php

namespace App\Support;

use Filament\Navigation\NavigationItem;
use UnitEnum;

/**
 * The third level: splitting the long groups into branches that open and close.
 *
 * Two of these groups were unusable as flat lists — Employee had twenty entries and Accounting
 * twelve, which is a scroll rather than a menu. This breaks them into named branches, so the People
 * column reads "Employees / Job changes / Payroll / Leave / Attendance & time / Lifecycle /
 * Projects" and you open the one you want.
 *
 * **A branch is not a filing cabinet, and it goes the same way a group did if nobody watches it.**
 * Re-cut on 2026-10-30 after the §14 depth phases: Employee's first branch had reached eleven
 * entries and Settings' first had reached fourteen, because every phase that added a screen put it
 * in the branch nearest its subject and nobody counted. Nothing below is longer than eight, which
 * is the length at which a branch stops being readable at a glance — the same judgement that made
 * this class exist, applied to what it produced.
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
                // The letter queue — plan §14.7. Beside Change Requests: both are an employee
                // asking for something and somebody deciding.
                'Letter Requests',
                // What the company tells its people — plan §7.6.
                'Announcements',
            ],
            /*
             * Probation, transfer, promotion — docs/saas-launch-plan.md §14.2.
             *
             * Their own branch since 2026-10-30. They were under Employees, which had reached
             * eleven entries and was a scroll again — the thing this class exists to prevent.
             * They belong together and apart: all three write a dated job-history row, and
             * none of them is where anybody goes to look somebody up.
             */
            'Job changes' => [
                'Probations',
                'Transfers',
                'Promotions',
            ],
            'Payroll' => [
                'Payroll Months',
                'Payslips',
                'Pay Adjustments',
                'Provident Fund',
                'Advances',
                'Expense Claims',
                'Final Settlements',
            ],
            'Leave' => [
                'Leave Requests',
                'Leave Types',
                'Leave Balances',
                // docs/saas-launch-plan.md §14.4.
                'Leave Groups',
                'Leave Encashments',
                'Leave Calendar',
            ],
            'Attendance & time' => [
                'Attendance',
                'Attendance Corrections',
                'Work Patterns',
                // docs/saas-launch-plan.md §14.4.
                'Pattern Assignments',
                'Overtime Requests',
                'Rest-day Swaps',
                'Timesheets',
            ],
            /*
             * Joining and leaving: the documents, the kit and the checklist that tracks both.
             *
             * The two cards are here rather than in a branch of their own — plan §14.10. A
             * staff card is issued on the first day and cancelled on the last, which is this
             * branch's subject exactly; business cards are ordered on the same morning. A
             * two-entry branch beside this one would be a click in front of a list.
             */
            'Lifecycle' => [
                'Checklist Templates',
                'Employee Documents',
                'Issued Assets',
                'ID Cards',
                'Business Cards',
                // docs/saas-launch-plan.md §14.5.
                'Employment Contracts',
                'Separations',
                'Checklist Items',
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
            // Money moving through a bank: the accounts, who is paid, and what was paid.
            'Banking' => [
                'Banks',
                'Company Bank Accounts',
                'Bank Statements',
                'Beneficiaries',
                'Payments',
            ],
            /*
             * The paper and the file formats — split out on 2026-10-30.
             *
             * Banking had nine entries and four of them were things somebody sets up once and
             * then never opens: a cheque layout is measured against a bank's stationery, a bank
             * file format against what the bank's portal will accept. Sitting among the five
             * screens a clerk uses daily, they made the branch look longer than it works.
             */
            'Cheques & bank files' => [
                'Cheques',
                'Cheque Books',
                'Cheque Layouts',
                'Bank File Formats',
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
                'Field Visits',
                // The weekly form, for a company without the pipeline — plan §14.10.
                'Weekly Sales Form',
                'Quotes',
                'Campaigns',
            ],
            'Sales setup' => [
                'Lead Sources',
                'Pipelines',
                // What a deal's Lost action picks from — beside the pipeline it belongs to.
                'Lost Reasons',
                'Sales Targets',
                /*
                 * Who does field visits — plan §14.10.
                 *
                 * Moved here on 2026-10-30, reversing the day-old decision to file it under
                 * Settings "beside the other who-may-do-what settings". That reasoning was
                 * sound about the *kind* of screen and wrong about the person: it is a sales
                 * manager's list, and they are in this column all day.
                 */
                'Field Visit Staff',
                // Who a campaign goes to: a list set up once and picked from, like the sources.
                'Segments',
            ],
        ],
        /*
         * Re-cut on 2026-10-30. Company had reached fourteen entries — a column of its own and
         * still a scroll — because every phase that added a setting put it there. Four of them
         * were not about the company at all.
         */
        'Settings' => [
            // What this company is, and what it has bought. Identity first, then the two
            // commercial rows: a reader asking "what are we on" and one asking "what is our
            // address" are the same person on the same screen.
            'Company' => [
                'Company Settings',
                // What the company's own dropdowns offer — App\Support\OptionLists. Beside Company
                // Settings because that is what it is: the same decision, spread over several lists.
                'Dropdown Options',
                // Branches — plan §7.9. Beside Company Settings: where the company is.
                'Locations',
                'Custom Fields',
                // What the product does about each law — plan §14.10.
                'Statutory Coverage',
                'Modules',
                // What the company pays for — plan §6. Beside Modules: what it has bought.
                'Subscription',
            ],
            /*
             * Everything the application says in this company's name — plan §14.7, §14.9.
             *
             * Its own branch since 2026-10-30. The three were scattered through Company, where
             * "Email Wording" sat between "Custom Fields" and "Scheduled Reports" and nothing
             * said they were the same decision in three media.
             */
            'Messages' => [
                'Email Wording',
                'Letter Templates',
                'Scheduled Reports',
            ],
            /*
             * Where this company's data goes, and what may come and get it — plan §14.9.
             *
             * Two entries, which this class usually says is a click in front of a list. It is a
             * branch anyway because the alternative was leaving them in Company, where an
             * administrator reading "what is our address" met "API Keys" on the way.
             */
            'Integrations' => [
                'Webhooks',
                'API Keys',
            ],
            'Payroll setup' => [
                'Pay Components',
                'Salary Structures',
                'Salary Slabs',
                // Claims and advances — docs/saas-launch-plan.md §14.3.
                'Expense Categories',
                'Advance Types',
            ],
            // Departments and designations as rows — docs/saas-launch-plan.md §14.2.
            'People setup' => [
                'Departments',
                'Designations',
                // Keyed on a designation, so it belongs beside the designations — plan §14.10.
                // It spent one day in Company, where nothing about it made sense.
                'Scorecard Profiles',
            ],
            'Calendar & currency' => [
                'Fiscal Years',
                'Holidays',
                // docs/saas-launch-plan.md §14.4.
                'Holiday Calendar',
                'Currencies',
            ],
            /*
             * The statuses a board may have — docs/project-management-plan.md §4.1.
             *
             * Its own branch rather than a line in Company, and one entry is enough to justify it:
             * a workflow is not a company detail, it is the shape of how work moves, and Phase 2's
             * board settings land beside it. Sorted after People setup because a company writes its
             * departments before it writes its statuses.
             */
            'Work setup' => [
                'Workflows',
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
