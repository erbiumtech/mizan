<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'invoicing',
    'label' => 'Invoicing',
    'description' => 'Contacts and invoices. Posts journal entries through Accounting.',
    'requires' => [
        'accounting',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Invoicing\InvoicingPlugin::class,

    'models' => [
        'App\\Models\\Contact' => \App\Modules\Invoicing\Models\Contact::class,
        'App\\Models\\Invoice' => \App\Modules\Invoicing\Models\Invoice::class,
        'App\\Models\\InvoiceLine' => \App\Modules\Invoicing\Models\InvoiceLine::class,
        'App\\Models\\TaxRate' => \App\Modules\Invoicing\Models\TaxRate::class,
        'App\\Models\\InvoiceEvent' => \App\Modules\Invoicing\Models\InvoiceEvent::class,
        'App\\Models\\ContactPerson' => \App\Modules\Invoicing\Models\ContactPerson::class,
        'App\\Models\\RecurringInvoice' => \App\Modules\Invoicing\Models\RecurringInvoice::class,
        'App\\Models\\RecurringInvoiceLine' => \App\Modules\Invoicing\Models\RecurringInvoiceLine::class,
        'App\\Models\\FbrSubmission' => \App\Modules\Invoicing\Models\FbrSubmission::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Contacts\\ContactResource' => \App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource::class,
        'App\\Filament\\Resources\\Invoices\\InvoiceResource' => \App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource::class,
        'App\\Filament\\Resources\\InvoiceLines\\InvoiceLineResource' => \App\Modules\Invoicing\Filament\Resources\InvoiceLines\InvoiceLineResource::class,
        'App\\Filament\\Resources\\TaxRates\\TaxRateResource' => \App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource::class,
    ],

    'pages' => [
        // Revenue by dimension (reports-expansion-plan.md Phase 3.4).
        'App\\Filament\\Pages\\RevenueByDimension' => \App\Modules\Invoicing\Filament\Pages\RevenueByDimension::class,
        'App\\Filament\\Pages\\AgedReceivables' => \App\Modules\Invoicing\Filament\Pages\AgedReceivables::class,
        'App\\Filament\\Pages\\AgedPayables' => \App\Modules\Invoicing\Filament\Pages\AgedPayables::class,
        'App\\Filament\\Pages\\FbrInvoiceReporting' => \App\Modules\Invoicing\Filament\Pages\FbrInvoiceReporting::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\ReceivablesPayablesOverview' => \App\Modules\Invoicing\Filament\Widgets\ReceivablesPayablesOverview::class,
    ],

    'permission_groups' => [
        'Invoicing',
    ],

    'permissions' => [
        ['name' => 'ContactView', 'group' => 'Invoicing'],
        ['name' => 'ContactCreate', 'group' => 'Invoicing'],
        ['name' => 'ContactUpdate', 'group' => 'Invoicing'],
        ['name' => 'ContactDelete', 'group' => 'Invoicing'],
        ['name' => 'InvoiceView', 'group' => 'Invoicing'],
        ['name' => 'InvoiceCreate', 'group' => 'Invoicing'],
        ['name' => 'InvoiceUpdate', 'group' => 'Invoicing'],
        ['name' => 'InvoiceIssue', 'group' => 'Invoicing'],
        ['name' => 'InvoicePay', 'group' => 'Invoicing'],
        ['name' => 'InvoiceVoid', 'group' => 'Invoicing'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // Records, does not approve.
        'Accountant' => [
            'ContactCreate',
            'ContactUpdate',
            'ContactView',
            'InvoiceCreate',
            'InvoiceIssue',
            'InvoicePay',
            'InvoiceUpdate',
            'InvoiceView',
        ],
        // On top of Accountant.
        'Manager' => [
            'InvoiceVoid',
        ],
        // On top of Manager.
        'CEO' => [
            'ContactDelete',
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
        'Invoicing & Inventory' => 'finance',
        'Reports' => 'reports',
    ],
];
