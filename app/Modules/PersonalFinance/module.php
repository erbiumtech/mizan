<?php

/**
 * What this module is, and what it owns.
 *
 * Requires Accounting, and genuinely so rather than for tidiness: a personal
 * account keeps its books in the tenant's own chart of accounts and journal
 * entries, and the tax estimate is computed by reading the income posted
 * there. Without Accounting there is nothing for it to add up.
 *
 * It briefly had its own parallel ledger and therefore no dependency. That
 * was the wrong shape — see docs/personal-finance-plan.md.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'personal_finance',
    'label' => 'Personal Finance',
    'description' => 'Individual Pakistani income tax estimate over a personal account\'s own books.',
    'requires' => [
        'accounting',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\PersonalFinance\PersonalFinancePlugin::class,

    'models' => [
        'App\\Models\\PersonalTaxProfile' => \App\Modules\PersonalFinance\Models\PersonalTaxProfile::class,
        'App\\Models\\TaxSchedule' => \App\Modules\PersonalFinance\Models\TaxSchedule::class,
        'App\\Models\\TaxSurcharge' => \App\Modules\PersonalFinance\Models\TaxSurcharge::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\TaxEstimate' => \App\Modules\PersonalFinance\Filament\Pages\TaxEstimate::class,
    ],

    'permission_groups' => [
        'PersonalFinance',
    ],

    'permissions' => [
        // A person's own books. These grant access to your *own* records
        // only — which rows you can reach is decided by the owner scope on
        // the models, not by holding a permission. PersonalFinanceViewAny is
        // the exception: it is the read-only cross-user view, and no seeded
        // role but Administrator holds it.
        ['name' => 'PersonalFinanceView', 'group' => 'PersonalFinance'],
        ['name' => 'PersonalFinanceCreate', 'group' => 'PersonalFinance'],
        ['name' => 'PersonalFinanceUpdate', 'group' => 'PersonalFinance'],
        ['name' => 'PersonalFinanceDelete', 'group' => 'PersonalFinance'],
        ['name' => 'PersonalFinanceViewAny', 'group' => 'PersonalFinance'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // Every member of staff.
        'Employee' => [
            'PersonalFinanceCreate',
            'PersonalFinanceDelete',
            'PersonalFinanceUpdate',
            'PersonalFinanceView',
        ],
        // Records, does not approve.
        'Accountant' => [
            'PersonalFinanceCreate',
            'PersonalFinanceDelete',
            'PersonalFinanceUpdate',
            'PersonalFinanceView',
        ],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     */
    'navigation' => [
        'Personal' => 'home',
    ],
];
