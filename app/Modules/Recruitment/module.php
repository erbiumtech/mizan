<?php

/**
 * What this module is, and what it owns.
 *
 * Requires NOTHING, deliberately: an applicant is not an employee, and a company
 * hiring its first person has no `employees` licence yet. The CONVERSION is the
 * guarded part — the Hire action is absent without `employees`, and the salary
 * package is skipped without `payroll`.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'recruitment',
    'label' => 'Recruitment',
    'description' => 'Vacancies, applicants, applications, interviews, offers, and the hire that creates an employee.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Recruitment\RecruitmentPlugin::class,

    'models' => [
        'App\\Models\\Vacancy' => \App\Modules\Recruitment\Models\Vacancy::class,
        'App\\Models\\Applicant' => \App\Modules\Recruitment\Models\Applicant::class,
        'App\\Models\\Application' => \App\Modules\Recruitment\Models\Application::class,
        'App\\Models\\Interview' => \App\Modules\Recruitment\Models\Interview::class,
        'App\\Models\\Offer' => \App\Modules\Recruitment\Models\Offer::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Vacancies\\VacancyResource' => \App\Modules\Recruitment\Filament\Resources\Vacancies\VacancyResource::class,
        'App\\Filament\\Resources\\Applicants\\ApplicantResource' => \App\Modules\Recruitment\Filament\Resources\Applicants\ApplicantResource::class,
        'App\\Filament\\Resources\\Applications\\ApplicationResource' => \App\Modules\Recruitment\Filament\Resources\Applications\ApplicationResource::class,
    ],

    'permission_groups' => [
        'Vacancy',
        'Applicant',
        'Offer',
    ],

    'permissions' => [
        // Hiring. Applicants, applications, interviews and offers share ONE group:
        // splitting them would invite a role that can read CVs without being trusted
        // with the rest, and a CV is the most sensitive record here.
        ['name' => 'VacancyView', 'group' => 'Vacancy'],
        ['name' => 'VacancyCreate', 'group' => 'Vacancy'],
        ['name' => 'VacancyUpdate', 'group' => 'Vacancy'],
        ['name' => 'VacancyDelete', 'group' => 'Vacancy'],
        ['name' => 'ApplicantView', 'group' => 'Applicant'],
        ['name' => 'ApplicantCreate', 'group' => 'Applicant'],
        ['name' => 'ApplicantUpdate', 'group' => 'Applicant'],
        ['name' => 'ApplicantDelete', 'group' => 'Applicant'],
        // Its own permission: creating an employee, and a salary package with them,
        // is a bigger decision than moving somebody through a pipeline.
        ['name' => 'OfferHire', 'group' => 'Offer'],
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
        'Hiring' => 'people',
    ],
];
