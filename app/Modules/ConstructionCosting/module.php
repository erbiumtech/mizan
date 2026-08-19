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
        'App\\Models\\Commitment' => \App\Modules\ConstructionCosting\Models\Commitment::class,
        'App\\Models\\CommitmentLine' => \App\Modules\ConstructionCosting\Models\CommitmentLine::class,
        'App\\Models\\CommitmentRelief' => \App\Modules\ConstructionCosting\Models\CommitmentRelief::class,
        'App\\Models\\Requisition' => \App\Modules\ConstructionCosting\Models\Requisition::class,
        'App\\Models\\RequisitionLine' => \App\Modules\ConstructionCosting\Models\RequisitionLine::class,
        'App\\Models\\GoodsReceipt' => \App\Modules\ConstructionCosting\Models\GoodsReceipt::class,
        'App\\Models\\GoodsReceiptLine' => \App\Modules\ConstructionCosting\Models\GoodsReceiptLine::class,
        'App\\Models\\InvoiceAllocation' => \App\Modules\ConstructionCosting\Models\InvoiceAllocation::class,
        // The alias is `App\Models\{ClassBasename}` — `ModuleCoverageTest` asserts it, because those strings are
        // what customer rows in `activity_log.subject_type` and `custom_fields.model_type` already hold. None of
        // these three basenames is taken by another module, which is what makes the short class names safe here;
        // Phase 3 had to reach for `JobBudget` because `BudgetLine` was not.
        'App\\Models\\Trade' => \App\Modules\ConstructionCosting\Models\Trade::class,
        'App\\Models\\Worker' => \App\Modules\ConstructionCosting\Models\Worker::class,
        'App\\Models\\LabourRate' => \App\Modules\ConstructionCosting\Models\LabourRate::class,
        'App\\Models\\LabourRecord' => \App\Modules\ConstructionCosting\Models\LabourRecord::class,
        'App\\Models\\MaterialIssue' => \App\Modules\ConstructionCosting\Models\MaterialIssue::class,
        'App\\Models\\MaterialIssueLine' => \App\Modules\ConstructionCosting\Models\MaterialIssueLine::class,
        'App\\Models\\PlantItem' => \App\Modules\ConstructionCosting\Models\PlantItem::class,
        'App\\Models\\PlantLog' => \App\Modules\ConstructionCosting\Models\PlantLog::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionCosting\\JobBudgetResource' => \App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\JobBudgetResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\CostEntryResource' => \App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\CommitmentResource' => \App\Modules\ConstructionCosting\Filament\Resources\Commitments\CommitmentResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\RequisitionResource' => \App\Modules\ConstructionCosting\Filament\Resources\Requisitions\RequisitionResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\GoodsReceiptResource' => \App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\GoodsReceiptResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\WorkerResource' => \App\Modules\ConstructionCosting\Filament\Resources\Workers\WorkerResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\TradeResource' => \App\Modules\ConstructionCosting\Filament\Resources\Trades\TradeResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\LabourRateResource' => \App\Modules\ConstructionCosting\Filament\Resources\LabourRates\LabourRateResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\LabourRecordResource' => \App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\LabourRecordResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\MaterialIssueResource' => \App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\MaterialIssueResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\PlantItemResource' => \App\Modules\ConstructionCosting\Filament\Resources\PlantItems\PlantItemResource::class,
        'App\\Filament\\Resources\\ConstructionCosting\\PlantLogResource' => \App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\PlantLogResource::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\ConstructionCosting\\JobCostReport' => \App\Modules\ConstructionCosting\Filament\Pages\JobCostReport::class,
        'App\\Filament\\Pages\\ConstructionCosting\\InvoiceAllocationQueue' => \App\Modules\ConstructionCosting\Filament\Pages\InvoiceAllocationQueue::class,
        'App\\Filament\\Pages\\ConstructionCosting\\ThreeWayMatchReport' => \App\Modules\ConstructionCosting\Filament\Pages\ThreeWayMatchReport::class,
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

        /*
         * Procurement rides on the same group — it is the same screenful of decisions about the same job — but
         * **`Approve`, `Issue` and `Close` are separate names**, because they are three decisions taken at three
         * moments and often by three people. Approving says this company will spend the money; issuing tells the
         * supplier, which is what makes it a commitment somebody else is relying on; closing writes off whatever
         * was never delivered, which is the one that needs an author and a reason on it.
         */
        ['name' => 'ConstructionCommitmentView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCommitmentCreate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCommitmentApprove', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCommitmentIssue', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionCommitmentClose', 'group' => 'ConstructionCost'],

        /*
         * Requisitions, and this is the **only place in the construction suite where site staff get a create**: the
         * demand document exists because the demand comes from the people who need the material, and a requisition
         * raised only by the commercial office is a purchase order with an extra step.
         *
         * Approving is its own name and commits nothing — it says the need is real. The money is committed by
         * `ConstructionCommitmentIssue`, which is a different grant held by a different person.
         */
        ['name' => 'ConstructionRequisitionView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionRequisitionCreate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionRequisitionApprove', 'group' => 'ConstructionCost'],

        /*
         * Goods receipts. **Recording one is site's**, like raising a requisition: the storeman signs the delivery
         * note and is the only person who knows what actually arrived. A receipt typed by the office from a note that
         * reached it a week later is how a delivery comes to be recorded against the wrong job.
         *
         * Reversing is separate, because a posted receipt has relieved an order and put accrued cost on a job.
         */
        ['name' => 'ConstructionReceiptView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionReceiptRecord', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionReceiptReverse', 'group' => 'ConstructionCost'],

        /*
         * Attributing a supplier invoice to jobs and codes. Its own name because it is the act that answers §5's
         * "single most likely silent failure in the module" — an invoice posted with no allocation leaves the accounts
         * perfectly correct and the job under-costed — and because the person who codes an invoice is the commercial
         * one rather than whoever received the delivery.
         */
        ['name' => 'ConstructionInvoiceAllocate', 'group' => 'ConstructionCost'],

        /*
         * Accepting a three-way variance. Reading the report rides on `ConstructionCommitmentView` — it is the same
         * screenful of facts about the same orders — but **accepting is its own name**, because §5 puts the control at
         * acceptance rather than at payment: this is the grant that lets somebody say a difference between what was
         * ordered, what arrived and what was billed is acceptable, and put their name to it.
         */
        ['name' => 'ConstructionVarianceAccept', 'group' => 'ConstructionCost'],

        /*
         * Labour (§7), and it rides on the same group for the reason procurement does: it is the same screenful of
         * decisions about the same job's cost, and a group of its own would be another section in every role form for
         * no decision anybody makes separately.
         *
         * **`RateSet` is separate from `Update`, and it is the one that matters.** Filing the six men who turned up on
         * Monday is a ganger's administration; deciding what an hour of steel fixing costs reaches every job at once,
         * and a company-default rate revised by ten per cent restates the labour cost of everything booked from that
         * date. §7.2 makes the dated table the first line of defence against that being done casually — this
         * permission is who is allowed to do it at all.
         */
        ['name' => 'ConstructionLabourView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionLabourUpdate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionLabourRateSet', 'group' => 'ConstructionCost'],

        /*
         * Recording a day's work, and approving it. **Recording is site's**, like a goods receipt: the ganger is the
         * only person who knows who turned up, and a sheet typed by the office from a note that reached it a week
         * later is how a day lands on the wrong job. **Approving is what books the money**, and it is also what
         * freezes the rate the day was costed at (§7.1's snapshot) — so it sits with somebody who reads the sheet
         * rather than with whoever collected it.
         *
         * Reversing booked labour needs no name of its own: `ConstructionCostReverse` already governs backing a
         * posted entry out of the ledger, which is exactly what a reversal here does — twice, since §7.3's burden is
         * its own entry.
         */
        ['name' => 'ConstructionLabourRecord', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionLabourApprove', 'group' => 'ConstructionCost'],

        /*
         * Plant (§7.3), and the shape mirrors labour with one deliberate difference: **there is no separate rate
         * permission.** A labour rate is a five-tier dated ladder whose company default reaches every job at once, so
         * `ConstructionLabourRateSet` earns its own name. A plant rate is one number on one machine, set when it joins
         * the fleet by the same person who registers it — so it rides on `Update`, and a fifth name would be a fifth
         * row in every role form for a decision nobody makes separately.
         *
         * `Log` is site's, like the goods receipt and the site sheet: the only people who know whether the excavator
         * worked, stood idle or sat on standby are the people who were there. `Approve` books internal hire on an owned
         * machine and fixes the figure a supplier's invoice is checked against on a hired one.
         */
        ['name' => 'ConstructionPlantView', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionPlantUpdate', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionPlantLog', 'group' => 'ConstructionCost'],
        ['name' => 'ConstructionPlantApprove', 'group' => 'ConstructionCost'],

        /*
         * Issuing material out of a site store (§6). **One name, covering the docket and the posting**, exactly as
         * `ConstructionReceiptRecord` does for a delivery: the storeman signs the paper and the stock moves in the same
         * act, and splitting them would leave a queue of dockets whose material has physically gone.
         *
         * Reading the register rides on `ConstructionReceiptView` — receiving into a store and issuing back out are the
         * same person's job on the same screenful. Reversing rides on `ConstructionCostReverse`, which already governs
         * backing a posted entry out of the ledger.
         */
        ['name' => 'ConstructionMaterialIssue', 'group' => 'ConstructionCost'],
    ],

    'role_grants' => [
        // A site engineer sees what the job has cost. Recording and reversing are the commercial side's.
        'Employee' => [
            'ConstructionBudgetView',
            // A site engineer reads what is on order for the job they are on: "has the rebar been ordered" is a
            // site question, and the answer being invisible is what produces a second order for it.
            'ConstructionCommitmentView',
            'ConstructionCostView',
            // And **asks for materials**, which is the whole reason the demand document exists — then signs for them
            // when they arrive, which is the only moment anybody knows what actually turned up.
            'ConstructionReceiptRecord',
            'ConstructionReceiptView',
            'ConstructionRequisitionCreate',
            'ConstructionRequisitionView',
            // A site engineer reads the gang list and the rates their job is being charged at. Reading rather than
            // setting: "who is on site today" and "what are we paying for a mason" are both site questions, and the
            // second one is asked at the moment somebody queries a week's cost.
            'ConstructionLabourView',
            // **And records the day's work**, which is the second place site staff create in — the same argument as
            // the requisition and the goods receipt: the ganger is who knows who turned up.
            'ConstructionLabourRecord',
            // And logs the plant, for the same reason: whether the excavator worked, stood idle or sat on standby is
            // only knowable by somebody who was there.
            'ConstructionPlantLog',
            'ConstructionPlantView',
            // And issues it back out again, which is the same person and the same paper.
            'ConstructionMaterialIssue',
        ],
        'Accountant' => [
            // The surveyor builds the budget, measures progress and prepares the forecast. Approving it and
            // fixing the baseline are somebody else's — below.
            'ConstructionBudgetUpdate',
            'ConstructionBudgetView',
            'ConstructionCommitmentCreate',
            'ConstructionCommitmentView',
            'ConstructionCostCreate',
            // The commercial side records deliveries too: on a job with no storeman, the surveyor is who does it.
            'ConstructionReceiptRecord',
            'ConstructionReceiptView',
            'ConstructionRequisitionCreate',
            'ConstructionRequisitionView',
            'ConstructionCostUpdate',
            'ConstructionCostView',
            'ConstructionForecastPrepare',
            'ConstructionInvoiceAllocate',
            'ConstructionProgressMeasure',
            // The commercial side maintains the trade list and the gang register. What an hour costs is not theirs
            // to decide — that is `ConstructionLabourRateSet`, below.
            'ConstructionLabourUpdate',
            'ConstructionLabourView',
            // The commercial side enters site sheets too: on a job with no ganger typing them, the surveyor does it.
            'ConstructionLabourRecord',
            // The fleet register and its rates are the commercial side's, including the internal hire rate — which is
            // one number on one machine rather than labour's ladder.
            'ConstructionPlantUpdate',
            'ConstructionPlantView',
            'ConstructionPlantLog',
            // On a job with no storeman, the surveyor writes the docket — the same reasoning as the goods receipt.
            'ConstructionMaterialIssue',
        ],
        // Reversing a posted entry and closing a period are approval-shaped acts, kept away from whoever
        // records the cost — the same segregation of duties the journal-entry powers already keep.
        'Manager' => [
            'ConstructionBudgetApprove',
            // Approving an order commits the company's money; issuing it commits the company to a supplier.
            'ConstructionCommitmentApprove',
            'ConstructionCommitmentIssue',
            // Backing out a posted receipt takes cost off a job and puts commitment back on an order.
            'ConstructionReceiptReverse',
            // Saying a difference between ordered, received and invoiced is acceptable — with a name on it.
            'ConstructionVarianceAccept',
            // Agreeing that a site request is real, which is the gate before any of that.
            'ConstructionRequisitionApprove',
            'ConstructionCostReverse',
            'ConstructionPeriodClose',
            /*
             * What an hour of labour costs. Approval-shaped, and kept away from whoever files the workers — a
             * company-default rate revised by ten per cent restates the labour cost of everything booked from that
             * date, across every job at once. §7.2's dated table means the revision cannot rewrite the past; this
             * grant is who may make it at all.
             */
            'ConstructionLabourRateSet',
            // Approving a site sheet books the cost and freezes the rate it was costed at. Kept away from whoever
            // collected the sheet, which is the same segregation the goods receipt keeps.
            'ConstructionLabourApprove',
            // And approving a plant log charges the job internal hire — or, on hired plant, fixes the figure the
            // supplier's invoice will be checked against.
            'ConstructionPlantApprove',
        ],
        // Closing over an unexplained difference between the two ledgers is the one that needs a name on it.
        'CEO' => [
            // Setting the baseline decides what every earned-value figure on the job is measured against, and
            // re-setting it restates all of them — so it sits with the person who answers for the numbers.
            'ConstructionBudgetBaseline',
            // Closing an order with a balance writes off money somebody committed. It needs a name on it.
            'ConstructionCommitmentClose',
            'ConstructionPeriodForceClose',
        ],
    ],

    'navigation' => [
        'Construction' => 'construction',
    ],
];
