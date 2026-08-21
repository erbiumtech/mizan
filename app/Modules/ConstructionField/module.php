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
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionField\\DelayEventResource' => \App\Modules\ConstructionField\Filament\Resources\DelayEvents\DelayEventResource::class,
        'App\\Filament\\Resources\\ConstructionField\\DailyLogResource' => \App\Modules\ConstructionField\Filament\Resources\DailyLogs\DailyLogResource::class,
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
        ],
        'Accountant' => [
            'ConstructionDelayUpdate',
            'ConstructionDelayView',
            'ConstructionDailyLogUpdate',
            'ConstructionDailyLogView',
        ],
        // Awarding days and money moves the completion date and decides whether damages can be levied. And signing off
        // a day locks it as evidence, which is a different act from writing it.
        'Manager' => [
            'ConstructionDelayDetermine',
            'ConstructionDailyLogApprove',
        ],
    ],

    'navigation' => [
        'Site' => 'construction',
    ],
];
