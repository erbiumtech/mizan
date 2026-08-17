<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 *
 * **This one genuinely requires `accounting`, unlike the spine** — `docs/construction-management-plan.md` §18.
 * The whole of §4 is reconciliation to the books, cost codes map to `accounts`, WIP is a journal entry and the
 * period close posts. "Job costing with no books is a spreadsheet, and this design would be almost entirely
 * dead code." The precedent is `personal_finance`, which requires Accounting for a weaker reason.
 *
 * It requires `construction` for the obvious reason: a cost entry names a job, a WBS node and a cost code, and
 * none of those exist without the spine.
 */
return [
    'key' => 'construction_costing',
    'label' => 'Construction Cost Control',
    'description' => 'The job-cost ledger, cost periods and the cost report — budget, committed, actual and forecast per cost code.',
    'requires' => [
        'construction',
        'accounting',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\ConstructionCosting\ConstructionCostingPlugin::class,

    'models' => [
        'App\\Models\\CostPeriod' => \App\Modules\ConstructionCosting\Models\CostPeriod::class,
        'App\\Models\\CostBatch' => \App\Modules\ConstructionCosting\Models\CostBatch::class,
        'App\\Models\\CostEntry' => \App\Modules\ConstructionCosting\Models\CostEntry::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionCosting\\CostEntryResource' => \App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\ConstructionCosting\\JobCostReport' => \App\Modules\ConstructionCosting\Filament\Pages\JobCostReport::class,
    ],

    'permission_groups' => [
        'ConstructionCost',
    ],

    'permissions' => [
        ['name' => 'ConstructionCostView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCostCreate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCostUpdate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCostReverse', 'group' => 'ConstructionCost'],
        // §18.2 lists this among the non-CRUD names that matter: closing a period fixes the figures a
        // certificate and a WIP snapshot were built on, and force-closing one over an unexplained difference
        // is a separate decision made by a separate person.
        ['name' => 'ConstructionPeriodClose', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionPeriodForceClose', 'group' => 'ConstructionCost'],
    ],

    'role_grants' => [
        // A site engineer sees what the job has cost. Recording and reversing are the commercial side's.
        'Employee' => [
            'ConstructionCostView',
        ],
        'Accountant' => [
            'ConstructionCostCreate',
            'ConstructionCostUpdate',
            'ConstructionCostView',
        ],
        // Reversing a posted entry and closing a period are approval-shaped acts, kept away from whoever
        // records the cost — the same segregation of duties the journal-entry powers already keep.
        'Manager' => [
            'ConstructionCostReverse',
            'ConstructionPeriodClose',
        ],
        // Closing over an unexplained difference between the two ledgers is the one that needs a name on it.
        'CEO' => [
            'ConstructionPeriodForceClose',
        ],
    ],

    'navigation' => [
        'Construction' => 'construction',
    ],
];
