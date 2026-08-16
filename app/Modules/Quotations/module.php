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
];
