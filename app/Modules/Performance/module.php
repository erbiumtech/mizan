<?php

/**
 * What this module is, and what it owns.
 *
 * Requires Employees. Guarded on `mpr`, which is the whole of the integration: a
 * review cycle READS the monthly progress reports in its period as evidence rather
 * than asking somebody to write the same thing twice. Guarded on `payroll` for the
 * suggested increment, which is a suggestion and writes nothing.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'performance',
    'label' => 'Performance',
    'description' => 'Review cycles, goals, ratings and one-to-ones. Ratings never touch pay.',
    'requires' => [
        'employees',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Performance\PerformancePlugin::class,

    'models' => [
        'App\\Models\\ReviewCycle' => \App\Modules\Performance\Models\ReviewCycle::class,
        'App\\Models\\Review' => \App\Modules\Performance\Models\Review::class,
        'App\\Models\\Goal' => \App\Modules\Performance\Models\Goal::class,
        'App\\Models\\OneToOne' => \App\Modules\Performance\Models\OneToOne::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ReviewCycles\\ReviewCycleResource' => \App\Modules\Performance\Filament\Resources\ReviewCycles\ReviewCycleResource::class,
        'App\\Filament\\Resources\\Reviews\\ReviewResource' => \App\Modules\Performance\Filament\Resources\Reviews\ReviewResource::class,
        'App\\Filament\\Resources\\Goals\\GoalResource' => \App\Modules\Performance\Filament\Resources\Goals\GoalResource::class,
        'App\\Filament\\Resources\\OneToOnes\\OneToOneResource' => \App\Modules\Performance\Filament\Resources\OneToOnes\OneToOneResource::class,
    ],

    'permission_groups' => [
        'Review',
    ],

    'permissions' => [
        // Appraisals. ReviewPrivateNotes is separate because it is the one thing an
        // employee must never hold about themselves, whatever else they can see.
        ['name' => 'ReviewView', 'group' => 'Review'],
        ['name' => 'ReviewCreate', 'group' => 'Review'],
        ['name' => 'ReviewUpdate', 'group' => 'Review'],
        ['name' => 'ReviewDelete', 'group' => 'Review'],
        ['name' => 'ReviewPrivateNotes', 'group' => 'Review'],
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
        'Performance' => 'people',
    ],
];
