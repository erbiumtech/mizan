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
];
