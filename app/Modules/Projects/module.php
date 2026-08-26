<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'projects',
    'label' => 'Projects',
    'description' => 'Projects, environment health monitoring, certificate expiry tracking and the public status page.',
    'requires' => [
        'employees',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Projects\ProjectsPlugin::class,

    'models' => [
        'App\\Models\\Project' => \App\Modules\Projects\Models\Project::class,
        'App\\Models\\ProjectEnvironment' => \App\Modules\Projects\Models\ProjectEnvironment::class,
        'App\\Models\\ProjectEnvironmentCheck' => \App\Modules\Projects\Models\ProjectEnvironmentCheck::class,
        'App\\Models\\ProjectEnvironmentIncident' => \App\Modules\Projects\Models\ProjectEnvironmentIncident::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Projects\\ProjectResource' => \App\Modules\Projects\Filament\Resources\Projects\ProjectResource::class,
    ],

    'widgets' => [
        'App\\Filament\\Resources\\Projects\\Widgets\\ProjectHealthChart' => \App\Modules\Projects\Filament\Resources\Projects\Widgets\ProjectHealthChart::class,
        'App\\Filament\\Widgets\\MyProjectsOverview' => \App\Modules\Projects\Filament\Widgets\MyProjectsOverview::class,
        'App\\Filament\\Widgets\\EnvironmentHealthOverview' => \App\Modules\Projects\Filament\Widgets\EnvironmentHealthOverview::class,
        'App\\Filament\\Widgets\\EnvironmentIncidentsTable' => \App\Modules\Projects\Filament\Widgets\EnvironmentIncidentsTable::class,
        'App\\Filament\\Widgets\\CertificateExpiryTable' => \App\Modules\Projects\Filament\Widgets\CertificateExpiryTable::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\EnvironmentHealth' => \App\Modules\Projects\Filament\Pages\EnvironmentHealth::class,
    ],

    'permission_groups' => [
        'Project',
    ],

    'permissions' => [
        ['name' => 'ProjectView', 'group' => 'Project'],
        ['name' => 'ProjectCreate', 'group' => 'Project'],
        ['name' => 'ProjectUpdate', 'group' => 'Project'],
        ['name' => 'ProjectDelete', 'group' => 'Project'],
        ['name' => 'ProjectHealthCheck', 'group' => 'Project'],
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
            'ProjectCreate',
            'ProjectUpdate',
            'ProjectView',
        ],
        // Records, does not approve.
        'Accountant' => [
            'ProjectCreate',
            'ProjectUpdate',
            'ProjectView',
        ],
        // On top of Accountant.
        'Manager' => [
            'ProjectHealthCheck',
        ],
        // On top of Manager.
        'CEO' => [
            'ProjectDelete',
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
        'Employee' => 'people',
    ],
];
