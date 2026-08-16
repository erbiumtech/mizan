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
];
