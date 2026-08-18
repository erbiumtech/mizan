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
        'App\\Models\\JobBudget' => \App\Modules\ConstructionCosting\Models\JobBudget::class,
        'App\\Models\\JobBudgetLine' => \App\Modules\ConstructionCosting\Models\JobBudgetLine::class,
        'App\\Models\\ProgressMeasurement' => \App\Modules\ConstructionCosting\Models\ProgressMeasurement::class,
        'App\\Models\\ForecastRun' => \App\Modules\ConstructionCosting\Models\ForecastRun::class,
        'App\\Models\\ForecastLine' => \App\Modules\ConstructionCosting\Models\ForecastLine::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionCosting\\JobBudgetResource' => \App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\JobBudgetResource::class,
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

        // Budget, measurement and forecast ride on the same group: they are one screenful of decisions taken by
        // the same person, and separate groups would be more rows in every role form for no decision anybody
        // makes separately (§18.2's Leave precedent).
        //
        // `Baseline` is its own name because it is its own decision: setting the baseline fixes what every
        // earned-value figure on the job is measured against, and re-setting it restates all of them.
        ['name' => 'ConstructionBudgetView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionBudgetUpdate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionBudgetApprove', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionBudgetBaseline', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionProgressMeasure', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionForecastPrepare', 'group' => 'ConstructionCost'],
    ],

    'role_grants' => [
        // A site engineer sees what the job has cost. Recording and reversing are the commercial side's.
        'Employee' => [
            'ConstructionBudgetView',
            'ConstructionCostView',
        ],
        'Accountant' => [
            // The surveyor builds the budget, measures progress and prepares the forecast. Approving it and
            // fixing the baseline are somebody else's — below.
            'ConstructionBudgetUpdate',
            'ConstructionBudgetView',
            'ConstructionCostCreate',
            'ConstructionCostUpdate',
            'ConstructionCostView',
            'ConstructionForecastPrepare',
            'ConstructionProgressMeasure',
        ],
        // Reversing a posted entry and closing a period are approval-shaped acts, kept away from whoever
        // records the cost — the same segregation of duties the journal-entry powers already keep.
        'Manager' => [
            'ConstructionBudgetApprove',
            'ConstructionCostReverse',
            'ConstructionPeriodClose',
        ],
        // Closing over an unexplained difference between the two ledgers is the one that needs a name on it.
        'CEO' => [
            // Setting the baseline decides what every earned-value figure on the job is measured against, and
            // re-setting it restates all of them — so it sits with the person who answers for the numbers.
            'ConstructionBudgetBaseline',
            'ConstructionPeriodForceClose',
        ],
    ],

    'navigation' => [
        'Construction' => 'construction',
    ],
];
