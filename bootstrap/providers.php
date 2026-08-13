<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Advances\AdvancesServiceProvider;
use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Campaigns\CampaignsServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Employees\EmployeesServiceProvider;
use App\Modules\Expenses\ExpensesServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Invoicing\InvoicingServiceProvider;
use App\Modules\Leave\LeaveServiceProvider;
use App\Modules\Lifecycle\LifecycleServiceProvider;
use App\Modules\Mpr\MprServiceProvider;
use App\Modules\Payroll\PayrollServiceProvider;
use App\Modules\Performance\PerformanceServiceProvider;
use App\Modules\PersonalFinance\PersonalFinanceServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Quotations\QuotationsServiceProvider;
use App\Modules\Recruitment\RecruitmentServiceProvider;
use App\Modules\Support\SupportServiceProvider;
use App\Modules\Timesheets\TimesheetsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PlatformPanelProvider::class,

    // One per module physically moved into app/Modules. Each carries its own
    // policies and routes; its Filament classes are registered by the matching
    // plugin listed in config/modules.php.
    InventoryServiceProvider::class,
    InvoicingServiceProvider::class,
    MprServiceProvider::class,
    EmployeesServiceProvider::class,
    ExpensesServiceProvider::class,
    LeaveServiceProvider::class,
    AttendanceServiceProvider::class,
    TimesheetsServiceProvider::class,
    LifecycleServiceProvider::class,
    RecruitmentServiceProvider::class,
    PerformanceServiceProvider::class,
    CrmServiceProvider::class,
    QuotationsServiceProvider::class,
    SupportServiceProvider::class,
    CampaignsServiceProvider::class,
    PayrollServiceProvider::class,
    ProjectsServiceProvider::class,
    AccountingServiceProvider::class,
    AdvancesServiceProvider::class,
    BillingServiceProvider::class,
    PersonalFinanceServiceProvider::class,
    CoreServiceProvider::class,
];
