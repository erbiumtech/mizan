<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Advances\AdvancesServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Employees\EmployeesServiceProvider;
use App\Modules\Expenses\ExpensesServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Invoicing\InvoicingServiceProvider;
use App\Modules\Mpr\MprServiceProvider;
use App\Modules\Payroll\PayrollServiceProvider;
use App\Modules\PersonalFinance\PersonalFinanceServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
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
    PayrollServiceProvider::class,
    ProjectsServiceProvider::class,
    AccountingServiceProvider::class,
    AdvancesServiceProvider::class,
    BillingServiceProvider::class,
    PersonalFinanceServiceProvider::class,
    CoreServiceProvider::class,
];
