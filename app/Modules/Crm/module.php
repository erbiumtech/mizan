<?php

/**
 * What this module is, and what it owns.
 *
 * Requires NOTHING, and that is the plan's one architectural decision
 * (docs/crms-plan.md §1). A prospect is not a contact you can invoice, so CRM
 * owns its own `leads` table and must be sellable to a company that has bought
 * neither Invoicing nor Accounting — a bookkeeping practice still has clients it
 * is pitching to.
 *
 * Two soft couplings, both guarded at the call site and recorded in
 * ModuleBoundaryTest::KNOWN_COUPLINGS rather than declared here:
 * - `invoicing` — converting a lead creates a Contact. Absent without it.
 * - `employees` — a lead's owner is an employee, so EmployeeAccess scoping
 * applies unchanged. Without it, ownership falls back to who created the row.
 * The precedent for guarding rather than requiring is invoicing -> projects.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'crm',
    'label' => 'CRM',
    'description' => 'Leads, their sources and owners, and conversion into a customer.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Crm\CrmPlugin::class,

    'models' => [
        'App\\Models\\Lead' => \App\Modules\Crm\Models\Lead::class,
        'App\\Models\\LeadSource' => \App\Modules\Crm\Models\LeadSource::class,
        'App\\Models\\Pipeline' => \App\Modules\Crm\Models\Pipeline::class,
        'App\\Models\\PipelineStage' => \App\Modules\Crm\Models\PipelineStage::class,
        'App\\Models\\LostReason' => \App\Modules\Crm\Models\LostReason::class,
        'App\\Models\\Opportunity' => \App\Modules\Crm\Models\Opportunity::class,
        'App\\Models\\OpportunityStageHistory' => \App\Modules\Crm\Models\OpportunityStageHistory::class,
        'App\\Models\\Activity' => \App\Modules\Crm\Models\Activity::class,
        'App\\Models\\NextAction' => \App\Modules\Crm\Models\NextAction::class,
        'App\\Models\\SalesTarget' => \App\Modules\Crm\Models\SalesTarget::class,
        'App\\Models\\FieldVisit' => \App\Modules\Crm\Models\FieldVisit::class,
        // Field visits, judged — docs/saas-launch-plan.md §14.10. The pin a visit is judged
        // against, who may be sent on one, and the clock-ins that were turned away.
        'App\\Models\\FieldVisitPlace' => \App\Modules\Crm\Models\FieldVisitPlace::class,
        'App\\Models\\FieldVisitStaff' => \App\Modules\Crm\Models\FieldVisitStaff::class,
        'App\\Models\\FieldVisitRefusal' => \App\Modules\Crm\Models\FieldVisitRefusal::class,
        // The weekly form, for a company without the pipeline — docs/saas-launch-plan.md §14.10.
        'App\\Models\\WeeklySalesReport' => \App\Modules\Crm\Models\WeeklySalesReport::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Leads\\LeadResource' => \App\Modules\Crm\Filament\Resources\Leads\LeadResource::class,
        'App\\Filament\\Resources\\LeadSources\\LeadSourceResource' => \App\Modules\Crm\Filament\Resources\LeadSources\LeadSourceResource::class,
        'App\\Filament\\Resources\\Opportunities\\OpportunityResource' => \App\Modules\Crm\Filament\Resources\Opportunities\OpportunityResource::class,
        'App\\Filament\\Resources\\Pipelines\\PipelineResource' => \App\Modules\Crm\Filament\Resources\Pipelines\PipelineResource::class,
        // The list a deal's Lost action picks from — there was no screen to add one.
        'App\\Filament\\Resources\\LostReasons\\LostReasonResource' => \App\Modules\Crm\Filament\Resources\LostReasons\LostReasonResource::class,
        'App\\Filament\\Resources\\NextActions\\NextActionResource' => \App\Modules\Crm\Filament\Resources\NextActions\NextActionResource::class,
        'App\\Filament\\Resources\\SalesTargets\\SalesTargetResource' => \App\Modules\Crm\Filament\Resources\SalesTargets\SalesTargetResource::class,
        'App\\Filament\\Resources\\FieldVisits\\FieldVisitResource' => \App\Modules\Crm\Filament\Resources\FieldVisits\FieldVisitResource::class,
        'App\\Filament\\Resources\\FieldVisitStaff\\FieldVisitStaffResource' => \App\Modules\Crm\Filament\Resources\FieldVisitStaff\FieldVisitStaffResource::class,
        'App\\Filament\\Resources\\FieldVisitRefusals\\FieldVisitRefusalResource' => \App\Modules\Crm\Filament\Resources\FieldVisitRefusals\FieldVisitRefusalResource::class,
        'App\\Filament\\Resources\\WeeklySalesReports\\WeeklySalesReportResource' => \App\Modules\Crm\Filament\Resources\WeeklySalesReports\WeeklySalesReportResource::class,
    ],

    /**
     * The five pipeline reports. Each is hidden from the sidebar and reached from the Reports hub, which is
     * why they are pages rather than a resource: a report is a question, not a table of rows to edit.
     */
    'pages' => [
        // The week per rep — docs/saas-launch-plan.md §14.10.
        'App\\Filament\\Pages\\WeeklySales' => \App\Modules\Crm\Filament\Pages\WeeklySales::class,
        'App\\Filament\\Pages\\PipelineByStage' => \App\Modules\Crm\Filament\Pages\PipelineByStage::class,
        'App\\Filament\\Pages\\SalesForecast' => \App\Modules\Crm\Filament\Pages\SalesForecast::class,
        'App\\Filament\\Pages\\WinLoss' => \App\Modules\Crm\Filament\Pages\WinLoss::class,
        'App\\Filament\\Pages\\RottingDeals' => \App\Modules\Crm\Filament\Pages\RottingDeals::class,
        'App\\Filament\\Pages\\TargetAttainment' => \App\Modules\Crm\Filament\Pages\TargetAttainment::class,
        'App\\Filament\\Pages\\VisitsPerRep' => \App\Modules\Crm\Filament\Pages\VisitsPerRep::class,
        'App\\Filament\\Pages\\UnconfirmedVisits' => \App\Modules\Crm\Filament\Pages\UnconfirmedVisits::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\ForecastAgainstTargetOverview' => \App\Modules\Crm\Filament\Widgets\ForecastAgainstTargetOverview::class,
        'App\\Filament\\Widgets\\PipelineFunnelChart' => \App\Modules\Crm\Filament\Widgets\PipelineFunnelChart::class,
    ],

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\OpportunityDataset' => \App\Modules\Crm\Reporting\OpportunityDataset::class,
    ],

    /**
     * Where this module's dropdowns are edited — App\Support\OptionLists.
     *
     * A row rather than a word because win/loss by source is the report worth having, and a table is what
     * keeps "LinkedIn", "Linkedin" and "linked in" from being three sources with three win rates.
     */
    'option_lists' => [
        'crm.lead_source' => [
            'label' => 'Lead sources',
            'help' => 'Where a lead came from. Add your own as you go — an expo, a chamber of commerce, a particular partner.',
            'managed_by' => 'App\\Filament\\Resources\\LeadSources\\LeadSourceResource',
        ],
    ],

    'permission_groups' => [
        'Lead',
        'LeadSource',
        'Pipeline',
        'Opportunity',
        'SalesTarget',
        'FieldVisit',
    ],

    'permissions' => [
        // CRM. Converting is its own permission: working a lead is not deciding
        // that it becomes a customer the ledger can bill.
        ['name' => 'LeadView', 'group' => 'Lead'],
        ['name' => 'LeadCreate', 'group' => 'Lead'],
        ['name' => 'LeadUpdate', 'group' => 'Lead'],
        ['name' => 'LeadDelete', 'group' => 'Lead'],
        ['name' => 'LeadConvert', 'group' => 'Lead'],
        // The pipeline family: a pipeline, its stages and the lost reasons are one
        // piece of setup, so one group covers all three.
        ['name' => 'PipelineView', 'group' => 'Pipeline'],
        ['name' => 'PipelineCreate', 'group' => 'Pipeline'],
        ['name' => 'PipelineUpdate', 'group' => 'Pipeline'],
        ['name' => 'PipelineDelete', 'group' => 'Pipeline'],
        // Deals, and the activities and next actions that hang off them — one group,
        // because logging a call and moving a deal are the same job. Closing is
        // separate: it is what targets are measured on and commission calculated from.
        ['name' => 'OpportunityView', 'group' => 'Opportunity'],
        ['name' => 'OpportunityCreate', 'group' => 'Opportunity'],
        ['name' => 'OpportunityUpdate', 'group' => 'Opportunity'],
        ['name' => 'OpportunityDelete', 'group' => 'Opportunity'],
        ['name' => 'OpportunityClose', 'group' => 'Opportunity'],
        // A salesperson works deals; they do not edit the number they are measured
        // against, which is why this is not part of the Opportunity group.
        ['name' => 'SalesTargetView', 'group' => 'SalesTarget'],
        ['name' => 'SalesTargetUpdate', 'group' => 'SalesTarget'],
        ['name' => 'LeadSourceView', 'group' => 'LeadSource'],
        ['name' => 'LeadSourceCreate', 'group' => 'LeadSource'],
        ['name' => 'LeadSourceUpdate', 'group' => 'LeadSource'],
        ['name' => 'LeadSourceDelete', 'group' => 'LeadSource'],
        // A visit is a rep's own record; its permissions follow the deal's.
        ['name' => 'FieldVisitView', 'group' => 'FieldVisit'],
        ['name' => 'FieldVisitCreate', 'group' => 'FieldVisit'],
        ['name' => 'FieldVisitUpdate', 'group' => 'FieldVisit'],
        ['name' => 'FieldVisitDelete', 'group' => 'FieldVisit'],
    ],

    /**
     * Who works the pipeline out of the box.
     *
     * Not the Employee role, deliberately — RoleSeeder says why. The Manager runs the sales desk:
     * leads and deals, the pipeline and its lists, targets and field visits, and closing a deal.
     * Deleting is the CEO's, as everywhere else. A company that wants its reps in here gives them a
     * role of its own on the Roles page, and these grants never undo that (see RoleSeeder::apply()).
     */
    'role_grants' => [
        'Manager' => [
            'LeadView', 'LeadCreate', 'LeadUpdate', 'LeadConvert',
            'LeadSourceView', 'LeadSourceCreate', 'LeadSourceUpdate',
            'PipelineView', 'PipelineCreate', 'PipelineUpdate',
            'OpportunityView', 'OpportunityCreate', 'OpportunityUpdate', 'OpportunityClose',
            'SalesTargetView', 'SalesTargetUpdate',
            'FieldVisitView', 'FieldVisitCreate', 'FieldVisitUpdate',
        ],
        'CEO' => [
            'LeadDelete', 'LeadSourceDelete', 'PipelineDelete', 'OpportunityDelete', 'FieldVisitDelete',
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
        'Sales' => 'sales',
        // The five reports declare the Reports group, as every report page in this application does.
        'Reports' => 'reports',
    ],
];
