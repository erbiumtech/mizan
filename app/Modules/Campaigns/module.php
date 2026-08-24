<?php

/**
 * What this module is, and what it owns.
 *
 * Requires `crm`: a campaign with no leads or customers to send to has no audience.
 *
 * **The only module here that can damage the company's reputation** — §6 puts it last for
 * that reason. Consent is a row and every send checks it; WhatsApp sends approved templates
 * only, because Meta's API refuses free text outside a service window and repeated attempts
 * risk the number.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'campaigns',
    'label' => 'Campaigns',
    'description' => 'Segments, campaigns and consent as a record. WhatsApp is template-gated.',
    'requires' => [
        'crm',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Campaigns\CampaignsPlugin::class,

    'models' => [
        'App\\Models\\Segment' => \App\Modules\Campaigns\Models\Segment::class,
        'App\\Models\\Campaign' => \App\Modules\Campaigns\Models\Campaign::class,
        'App\\Models\\CampaignSend' => \App\Modules\Campaigns\Models\CampaignSend::class,
        'App\\Models\\Consent' => \App\Modules\Campaigns\Models\Consent::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Campaigns\\CampaignResource' => \App\Modules\Campaigns\Filament\Resources\Campaigns\CampaignResource::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\ConsentRegister' => \App\Modules\Campaigns\Filament\Pages\ConsentRegister::class,
    ],

    'permission_groups' => [
        'Campaign',
    ],

    'permissions' => [
        // Campaigns. SENDING is separate from creating, because drafting a campaign is a
        // writing task and sending it is irreversible and reaches people outside the
        // company — on WhatsApp, one that can cost the company its number.
        ['name' => 'CampaignView', 'group' => 'Campaign'],
        ['name' => 'CampaignCreate', 'group' => 'Campaign'],
        ['name' => 'CampaignUpdate', 'group' => 'Campaign'],
        ['name' => 'CampaignDelete', 'group' => 'Campaign'],
        ['name' => 'CampaignSend', 'group' => 'Campaign'],
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
        'Sales' => 'sales',
    ],
];
