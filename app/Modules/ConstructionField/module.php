<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 *
 * **It requires only `construction`**, which `docs/construction-management-plan.md` §18 sets out: the site diary, RFIs,
 * submittals, punch lists, the programme and delay events all name a job, a WBS node and a location, and none of them
 * needs the books, a contract or a cost ledger to exist. A contractor buying site management while keeping its
 * commercial side elsewhere is a real customer.
 *
 * `construction_contracts` is **guarded, not required**, and the delay event is where that matters: §13 computes
 * `notice_required_by` from "occurred_on + contract notice days", so with a contract the period comes from its own
 * column and without one it comes from config. The notice clock — the most valuable thing in §13 — works either way.
 */
return [
    'key' => 'construction_field',
    'label' => 'Construction Site Operations',
    'description' => 'The site diary, RFIs, submittals, punch lists, the programme and delay events with their notice clock.',
    'requires' => [
        'construction',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\ConstructionField\ConstructionFieldPlugin::class,

    'models' => [
        'App\\Models\\DelayEvent' => \App\Modules\ConstructionField\Models\DelayEvent::class,
        'App\\Models\\DailyLog' => \App\Modules\ConstructionField\Models\DailyLog::class,
        'App\\Models\\DailyLogManpower' => \App\Modules\ConstructionField\Models\DailyLogManpower::class,
        'App\\Models\\DailyLogPlant' => \App\Modules\ConstructionField\Models\DailyLogPlant::class,
        'App\\Models\\DailyLogEvent' => \App\Modules\ConstructionField\Models\DailyLogEvent::class,
        'App\\Models\\DailyLogDelivery' => \App\Modules\ConstructionField\Models\DailyLogDelivery::class,
        'App\\Models\\DailyLogPhoto' => \App\Modules\ConstructionField\Models\DailyLogPhoto::class,
        'App\\Models\\Rfi' => \App\Modules\ConstructionField\Models\Rfi::class,
        'App\\Models\\Submittal' => \App\Modules\ConstructionField\Models\Submittal::class,
        'App\\Models\\SubmittalReview' => \App\Modules\ConstructionField\Models\SubmittalReview::class,
        'App\\Models\\PunchList' => \App\Modules\ConstructionField\Models\PunchList::class,
        'App\\Models\\PunchItem' => \App\Modules\ConstructionField\Models\PunchItem::class,
        'App\\Models\\PunchInspection' => \App\Modules\ConstructionField\Models\PunchInspection::class,
        'App\\Models\\ProgrammeActivity' => \App\Modules\ConstructionField\Models\ProgrammeActivity::class,
        'App\\Models\\ProgrammeActivityPredecessor' => \App\Modules\ConstructionField\Models\ProgrammeActivityPredecessor::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionField\\DelayEventResource' => \App\Modules\ConstructionField\Filament\Resources\DelayEvents\DelayEventResource::class,
        'App\\Filament\\Resources\\ConstructionField\\DailyLogResource' => \App\Modules\ConstructionField\Filament\Resources\DailyLogs\DailyLogResource::class,
        'App\\Filament\\Resources\\ConstructionField\\RfiResource' => \App\Modules\ConstructionField\Filament\Resources\Rfis\RfiResource::class,
        'App\\Filament\\Resources\\ConstructionField\\SubmittalResource' => \App\Modules\ConstructionField\Filament\Resources\Submittals\SubmittalResource::class,
        'App\\Filament\\Resources\\ConstructionField\\PunchListResource' => \App\Modules\ConstructionField\Filament\Resources\PunchLists\PunchListResource::class,
        'App\\Filament\\Resources\\ConstructionField\\ProgrammeActivityResource' => \App\Modules\ConstructionField\Filament\Resources\Activities\ActivityResource::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\ProgrammeImport' => \App\Modules\ConstructionField\Filament\Pages\ProgrammeImportPage::class,
    ],

    'permission_groups' => [
        'ConstructionField',
    ],

    /*
     * **`Determine` is its own name, and it is the one §18.2 would have listed had this phase existed when that section
     * was written.** Raising a delay event and serving notice of it is the site and commercial team's ordinary
     * administration — the whole point of §13's clock is that it should happen early and often. *Determining* one
     * awards days and money, moves the completion date, and decides whether liquidated damages can be levied at all.
     * One person holding both is the segregation this suite keeps everywhere else.
     */
    'permissions' => [
        ['name' => 'ConstructionDelayView', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionDelayUpdate', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionDelayDetermine', 'group' => 'ConstructionField'],

        /*
         * The site diary (§16.1). **`ConstructionDailyLogApprove` is its own name and §18.2 lists it**: approval locks
         * the day and turns it into evidence — "an editable site diary is not evidence" — which is not the same act as
         * writing it down. The same grant carries reopening, because whoever may sign a day off is who may unsign it.
         *
         * Writing the diary is site's and needs no argument: it is a record of what happened on site, written by
         * somebody who was there.
         */
        ['name' => 'ConstructionDailyLogView', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionDailyLogUpdate', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionDailyLogApprove', 'group' => 'ConstructionField'],

        /*
         * RFIs (§16.2), and **two permissions rather than three, which is a decision rather than an omission.**
         *
         * Raising is site's: the person who cannot build without an answer is the person standing in front of the
         * problem, and an RFI they cannot raise is a question asked by telephone and unprovable afterwards.
         *
         * *Recording the answer is the same grant.* The answer arrives by email and somebody transcribes it — clerical
         * work, not an approval. A second permission there would leave answers sitting in an inbox while the register
         * says the question is open, which is worse than the risk it guards against. What genuinely needs separating is
         * already separate: **raising the delay event behind an RFI's time impact asks for
         * `ConstructionDelayUpdate`**, because serving notice on the employer is not the same act as asking a question.
         */
        ['name' => 'ConstructionRfiView', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionRfiUpdate', 'group' => 'ConstructionField'],

        /*
         * Submittals (§16.3), and **two names again, for the same reason as the RFI's.**
         *
         * Recording a reviewer's return is transcription — the stamped drawing arrives from the Architect and somebody
         * files it — so it rides on the same grant as submitting. A permission there would leave stamped drawings in a
         * drawer while the register says the item is still out for review, and a register nobody believes about what is
         * outstanding has no purpose at all.
         *
         * The act that needed separating is separated: notifying a reviewer's overrun asks for
         * `ConstructionDelayUpdate`, because serving notice on the employer is not the same act as filing paperwork.
         */
        ['name' => 'ConstructionSubmittalView', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionSubmittalUpdate', 'group' => 'ConstructionField'],

        /*
         * Punch lists (§16.4), and **two names, where the third one would have been the tempting mistake.**
         *
         * Closing a punch item releases part of §11's AIA holdback, so it is the one act on this register that moves
         * money — and it is protected *structurally* rather than by a `ConstructionPunchClose` permission: an item
         * closes only when a re-inspection is recorded with a passing result. That is stronger than a grant, because a
         * permission can be given to the person who caused the defect and a missing passed inspection cannot be given
         * away at all.
         *
         * Raising items is site's for the same reason as the diary and the RFI: the person who can see that the sealant
         * is wrong is the one standing in front of it.
         */
        ['name' => 'ConstructionPunchView', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionPunchUpdate', 'group' => 'ConstructionField'],

        /*
         * The programme (§13), and **the first register in this module where a third name earns its place.**
         *
         * Reading and maintaining the programme is planning work. **Recording progress is separate**, because percent
         * complete and actual dates are what §14's earned value and every schedule index are computed from — and the
         * person who reports 80% is not usually the person who owns the consequence of it being 60%. Progress claimed
         * against a programme is the oldest optimism in construction, and it is the one number on that table that feeds
         * money.
         *
         * Note what is deliberately *not* a fourth name: the baseline. It is the accepted programme, and this
         * application does not accept programmes — it stores what P6 exported. Guarding a column only an import writes
         * would be theatre.
         */
        ['name' => 'ConstructionProgrammeView', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionProgrammeUpdate', 'group' => 'ConstructionField'],
        ['name' => 'ConstructionProgrammeProgress', 'group' => 'ConstructionField'],
    ],

    'role_grants' => [
        /*
         * **Site raises delay events, and this is deliberately a create grant for site staff** — the third in this
         * suite after the requisition, the goods receipt and the site sheet. §13: "a valid claim lost to a missed
         * notice is the single most common way a contractor donates money, and it fails in absolute silence." The
         * people who watch an access being blocked or a drawing arriving late are on site, and an event they cannot
         * record is an event nobody records.
         */
        'Employee' => [
            'ConstructionDelayUpdate',
            'ConstructionDelayView',
            // The diary is a record of what happened on site, written by somebody who was there.
            'ConstructionDailyLogUpdate',
            'ConstructionDailyLogView',
            // And so is an RFI: the question comes from whoever is blocked by it.
            'ConstructionRfiUpdate',
            'ConstructionRfiView',
            // Submittals are the same register-keeping work, done by the same people.
            'ConstructionSubmittalUpdate',
            'ConstructionSubmittalView',
            // And so are snags: whoever can see that the sealant is wrong is standing in front of it.
            'ConstructionPunchUpdate',
            'ConstructionPunchView',
            // Site reads the programme and reports its own progress against it — the look-ahead is site's document.
            'ConstructionProgrammeView',
            'ConstructionProgrammeProgress',
        ],
        'Accountant' => [
            'ConstructionDelayUpdate',
            'ConstructionDelayView',
            'ConstructionDailyLogUpdate',
            'ConstructionDailyLogView',
            'ConstructionRfiUpdate',
            'ConstructionRfiView',
            'ConstructionSubmittalUpdate',
            'ConstructionSubmittalView',
            'ConstructionPunchUpdate',
            'ConstructionPunchView',
            'ConstructionProgrammeView',
            'ConstructionProgrammeProgress',
        ],
        // Awarding days and money moves the completion date and decides whether damages can be levied. And signing off
        // a day locks it as evidence, which is a different act from writing it.
        'Manager' => [
            'ConstructionDelayDetermine',
            'ConstructionDailyLogApprove',
            // Maintaining the programme is planning work rather than site's: it is the document a claim is measured
            // against, and site reports progress against it rather than editing it.
            'ConstructionProgrammeUpdate',
        ],
    ],

    'navigation' => [
        'Site' => 'construction',
    ],
];
