<?php

return [
    // First, and it matters: every module provider below may read
    // config('modules') while booting, and this is what puts it there.
    App\Providers\ModuleManifestServiceProvider::class,

    // Also before the modules: each default is overridden by the module that
    // answers the question properly, and the last binding wins.
    App\Providers\ContractDefaultsServiceProvider::class,

    App\Modules\Accounting\AccountingServiceProvider::class,
    App\Modules\Advances\AdvancesServiceProvider::class,
    App\Modules\Attendance\AttendanceServiceProvider::class,
    App\Modules\Billing\BillingServiceProvider::class,
    App\Modules\Campaigns\CampaignsServiceProvider::class,
    App\Modules\Construction\ConstructionServiceProvider::class,
    App\Modules\ConstructionContracts\ConstructionContractsServiceProvider::class,
    App\Modules\ConstructionCosting\ConstructionCostingServiceProvider::class,
    App\Modules\ConstructionField\ConstructionFieldServiceProvider::class,
    App\Modules\Core\CoreServiceProvider::class,
    App\Modules\Crm\CrmServiceProvider::class,
    App\Modules\Employees\EmployeesServiceProvider::class,
    App\Modules\Expenses\ExpensesServiceProvider::class,
    App\Modules\Inventory\InventoryServiceProvider::class,
    App\Modules\Invoicing\InvoicingServiceProvider::class,
    App\Modules\Leave\LeaveServiceProvider::class,
    App\Modules\Lifecycle\LifecycleServiceProvider::class,
    App\Modules\Mpr\MprServiceProvider::class,
    App\Modules\Payroll\PayrollServiceProvider::class,
    App\Modules\Performance\PerformanceServiceProvider::class,
    App\Modules\PersonalFinance\PersonalFinanceServiceProvider::class,
    App\Modules\Projects\ProjectsServiceProvider::class,
    App\Modules\Quotations\QuotationsServiceProvider::class,
    App\Modules\Recruitment\RecruitmentServiceProvider::class,
    App\Modules\Support\SupportServiceProvider::class,
    App\Modules\Timesheets\TimesheetsServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\PlatformPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
