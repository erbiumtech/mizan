<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 *
 * **The spine of the construction suite, and it requires nothing.** That is
 * `docs/construction-management-plan.md` §18's one architectural decision, and the precedent is CRM's,
 * stated in the same terms: a contractor buying site management, document control and safety while keeping
 * its books in another system is a real and common customer. Requiring `accounting` here would undo the
 * self-contained decision of §1 in the registry, where it is least visible.
 *
 * The four modules that build on this one declare it: `construction_costing` (which does require
 * `accounting`, genuinely — job costing with no books is a spreadsheet), `construction_contracts`,
 * `construction_field` and `construction_qhse`.
 *
 * **The document register lives here, not in the field module** — §18 again. RFIs reference drawings,
 * submittals *are* documents, transmittals carry contract notices and ITPs reference
 * approved-for-construction drawings, so a customer who bought contracts and quality but not site
 * operations would otherwise have no register at all.
 */
return [
    'key' => 'construction',
    'label' => 'Construction',
    'description' => 'Jobs and sites, the work breakdown, the cost-code library and the ISO 19650 document register.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Construction\ConstructionPlugin::class,

    /*
     * Morph aliases take the legacy `App\Models\{Basename}` form even for classes that never lived there.
     * `ModuleCoverageTest` asserts it unconditionally with no exemption for new models, and
     * docs/new-module-checklist.md §4 records this being hit for real by a module that used short keys.
     */
    'models' => [
        // `App\Models\Job`, not `App\Models\ConstructionJob`: the rule is the *class basename*, and
        // `ModuleCoverageTest` asserts it unconditionally. §18.2's example reads `ConstructionCostEntry`
        // because that class is named that; this one is `Job`, per §1.1's rule that the model is `Job` and
        // never `Project`. The namespace is what disambiguates it, not the alias — which is an opaque
        // storage token nobody reads except the map.
        'App\\Models\\Job' => \App\Modules\Construction\Models\Job::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Construction\\JobResource' => \App\Modules\Construction\Filament\Resources\Jobs\JobResource::class,
    ],

    'permission_groups' => [
        'ConstructionJob',
    ],

    'permissions' => [
        ['name' => 'ConstructionJobView', 'group' => 'ConstructionJob'],
        ['name' => 'ConstructionJobCreate', 'group' => 'ConstructionJob'],
        ['name' => 'ConstructionJobUpdate', 'group' => 'ConstructionJob'],
        ['name' => 'ConstructionJobDelete', 'group' => 'ConstructionJob'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // A site engineer reads the job they are on; row scoping is what narrows it, not the permission.
        'Employee' => [
            'ConstructionJobView',
        ],
        // The commercial side builds and maintains jobs.
        'Accountant' => [
            'ConstructionJobCreate',
            'ConstructionJobUpdate',
            'ConstructionJobView',
        ],
        // Deleting a job with cost against it is refused by the model regardless; this is who may
        // remove one raised in error.
        'CEO' => [
            'ConstructionJobDelete',
        ],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     *
     * **Construction is a seventh domain**, decided in Phase 0 and not a fold into Finance: Finance is
     * already 24 classes across three groups, and this suite's four groups would push it past fifty across
     * seven — the flat-many-groups problem NavigationDomains exists to solve. A company that never buys
     * construction still sees six icons, because `rail()` omits a domain whose groups are all empty.
     */
    'navigation' => [
        'Construction' => 'construction',
    ],
];
