<?php

/**
 * What this module is, and what it owns.
 *
 * MPR keys on user_id rather than employee_id, so it does not actually need
 * the Employees module — the dependency in the module map is presentational,
 * not structural, and is deliberately not declared here.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'mpr',
    'label' => 'MPR',
    'description' => 'Monthly progress reports and the comparison export.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Mpr\MprPlugin::class,

    'models' => [
        'App\\Models\\MPR' => \App\Modules\Mpr\Models\MPR::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\MPRs\\MPRResource' => \App\Modules\Mpr\Filament\Resources\MPRs\MPRResource::class,
    ],

    'permission_groups' => [
        'MPR',
    ],

    'permissions' => [
        ['name' => 'MPRView', 'group' => 'MPR'],
        ['name' => 'MPRCreate', 'group' => 'MPR'],
        ['name' => 'MPRUpdate', 'group' => 'MPR'],
        ['name' => 'MPRDelete', 'group' => 'MPR'],
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
        'Employee' => 'people',
    ],
];
