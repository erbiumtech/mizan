<?php

/**
 * What this module is, and what it owns.
 *
 * Requires `invoicing`, and GENUINELY so rather than for tidiness: §2 — a quote whose
 * whole point is becoming an invoice, and which can never convert, is a PDF generator.
 * Guarded on `inventory` for product lines and on `crm` for a quote raised from a deal.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'quotations',
    'label' => 'Quotations',
    'description' => 'Quotes with supersession versioning, validity, and conversion into a draft invoice.',
    'requires' => [
        'invoicing',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Quotations\QuotationsPlugin::class,

    'models' => [
        'App\\Models\\Quotation' => \App\Modules\Quotations\Models\Quotation::class,
        'App\\Models\\QuotationLine' => \App\Modules\Quotations\Models\QuotationLine::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Quotations\\QuotationResource' => \App\Modules\Quotations\Filament\Resources\Quotations\QuotationResource::class,
    ],

    'permission_groups' => [
        'Quotation',
    ],

    'permissions' => [
        // Quotes. Converting is separate: it starts something that, once issued and
        // transmitted to FBR, cannot be freely undone after 72 hours.
        ['name' => 'QuotationView', 'group' => 'Quotation'],
        ['name' => 'QuotationCreate', 'group' => 'Quotation'],
        ['name' => 'QuotationUpdate', 'group' => 'Quotation'],
        ['name' => 'QuotationDelete', 'group' => 'Quotation'],
        ['name' => 'QuotationConvert', 'group' => 'Quotation'],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     */
    /** The conversion report (Phase 3.3). A page: a report is a question, not rows to edit. */
    'pages' => [
        'App\\Filament\\Pages\\QuotationConversion' => \App\Modules\Quotations\Filament\Pages\QuotationConversion::class,
    ],

    'navigation' => [
        // The report declares the Reports group, as every report page in this application does.
        'Reports' => 'reports',
        'Sales' => 'sales',
    ],
];
