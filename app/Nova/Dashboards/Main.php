<?php

namespace App\Nova\Dashboards;

use App\Nova\Metrics\AccountBalance;
use App\Nova\Metrics\ActiveEmployees;
use App\Nova\Metrics\DailyCashFlow;
use App\Nova\Metrics\PayrollByEmployee;
use App\Nova\Metrics\LowStockProducts;
use App\Nova\Metrics\PendingJournalEntries;
use App\Nova\Metrics\UnpaidInvoices;
use Laravel\Nova\Dashboards\Main as Dashboard;

class Main extends Dashboard
{
    /**
     * Get the cards for the dashboard.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(): array
    {
        $seesAccounts = fn ($request) => (bool) $request->user()?->can('AccountView');

        return [
            (new ActiveEmployees)->canSee(fn ($request) => (bool) $request->user()?->can('EmployeeView')),
            (new AccountBalance('Cash & Bank', ['1100', '1150']))->canSee($seesAccounts),
            (new AccountBalance('Accounts Receivable', ['1250']))->canSee($seesAccounts),
            (new AccountBalance('Accounts Payable', ['2400']))->canSee($seesAccounts),
            (new AccountBalance('Inventory Value', ['1300']))->canSee($seesAccounts),
            (new DailyCashFlow('in'))->width('1/2')->canSee($seesAccounts),
            (new DailyCashFlow('out'))->width('1/2')->canSee($seesAccounts),
            (new PayrollByEmployee)->width('1/2')->canSee(fn ($request) => (bool) $request->user()?->can('PayslipCreate')),
            (new PendingJournalEntries)->canSee(fn ($request) => (bool) $request->user()?->can('JournalEntryApprove')),
            (new UnpaidInvoices)->canSee(fn ($request) => (bool) $request->user()?->can('InvoiceView')),
            (new LowStockProducts)->canSee(fn ($request) => (bool) $request->user()?->can('ProductView')),
        ];
    }
}
