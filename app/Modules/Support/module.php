<?php

/**
 * What this module is, and what it owns.
 *
 * Requires nothing: a company can run a helpdesk without invoicing anybody through this
 * application. Guarded on `invoicing` for the customer, `projects` for the engagement and
 * `employees` for the assignee — each absent rather than broken.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'support',
    'label' => 'Support',
    'description' => 'Tickets, categories with SLA clocks that are measured rather than enforced, and internal replies.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Support\SupportPlugin::class,

    'models' => [
        'App\\Models\\TicketCategory' => \App\Modules\Support\Models\TicketCategory::class,
        'App\\Models\\Ticket' => \App\Modules\Support\Models\Ticket::class,
        'App\\Models\\TicketReply' => \App\Modules\Support\Models\TicketReply::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Tickets\\TicketResource' => \App\Modules\Support\Filament\Resources\Tickets\TicketResource::class,
        // The screen this table never had: categories carry the SLA commitments and were editable
        // by nobody.
        'App\\Filament\\Resources\\TicketCategories\\TicketCategoryResource' => \App\Modules\Support\Filament\Resources\TicketCategories\TicketCategoryResource::class,
    ],

    /**
     * The SLA report and its exception list. Pages rather than a resource: a report is a question, not a
     * table of rows to edit.
     */
    'pages' => [
        'App\\Filament\\Pages\\SlaPerformance' => \App\Modules\Support\Filament\Pages\SlaPerformance::class,
        'App\\Filament\\Pages\\SlaBreaches' => \App\Modules\Support\Filament\Pages\SlaBreaches::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\SlaComplianceOverview' => \App\Modules\Support\Filament\Widgets\SlaComplianceOverview::class,
    ],

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\TicketDataset' => \App\Modules\Support\Reporting\TicketDataset::class,
    ],

    /**
     * Where the ticket dropdowns are edited — App\Support\OptionLists.
     *
     * A pointer rather than a list of values: a category is a row carrying an SLA commitment and a default
     * priority, not a word, so it keeps its own screen. What it gains by being declared here is that the
     * one place an admin looks for "where do I add a category" can answer for it.
     */
    'option_lists' => [
        'support.ticket_category' => [
            'label' => 'Ticket categories',
            'help' => 'The kinds of ticket you take, each with the response and resolution times you have committed to.',
            'managed_by' => 'App\\Filament\\Resources\\TicketCategories\\TicketCategoryResource',
        ],

        // How the customer asked. A label and a reporting column, nothing more: no channel is
        // parsed or routed differently, and a company that takes tickets over Teams or at a
        // counter should be able to say so. "panel" stays: it is what a ticket raised from the
        // panel is stamped with by default.
        'support.ticket_channel' => [
            'label' => 'Ticket channels',
            'help' => 'How a customer raised the ticket. Shown on the ticket and in the ticket report; nothing is routed by it. Keep "panel": tickets raised in the panel are stamped with it.',
            'values' => [
                'phone' => 'Phone',
                'email' => 'Email',
                'whatsapp' => 'Whatsapp',
                'panel' => 'Panel',
                'in_person' => 'In person',
            ],
        ],
    ],

    'permission_groups' => [
        'Ticket',
    ],

    'permissions' => [
        // Tickets, their categories and their replies.
        ['name' => 'TicketView', 'group' => 'Ticket'],
        ['name' => 'TicketCreate', 'group' => 'Ticket'],
        ['name' => 'TicketUpdate', 'group' => 'Ticket'],
        ['name' => 'TicketDelete', 'group' => 'Ticket'],
    ],

    /**
     * Who answers tickets out of the box: the Manager, and the CEO deletes. A company with a support
     * desk of its own gives that desk a role on the Roles page; these grants never undo that.
     */
    'role_grants' => [
        'Manager' => ['TicketView', 'TicketCreate', 'TicketUpdate'],
        'CEO' => ['TicketDelete'],
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
        'Support' => 'admin',
        // The two reports declare the Reports group, as every report page in this application does.
        'Reports' => 'reports',
    ],
];
