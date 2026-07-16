<?php

namespace App\Nova\Dashboards;

use App\Nova\Metrics\LowStockProducts;
use App\Nova\Metrics\UnpaidInvoices;
use Laravel\Nova\Cards\Help;
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
        return [
            (new LowStockProducts)->canSee(fn ($request) => (bool) $request->user()?->can('ProductView')),
            (new UnpaidInvoices)->canSee(fn ($request) => (bool) $request->user()?->can('InvoiceView')),
            new Help,
        ];
    }
}
