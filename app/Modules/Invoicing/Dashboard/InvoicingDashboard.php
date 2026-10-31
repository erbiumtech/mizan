<?php

namespace App\Modules\Invoicing\Dashboard;

use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\DashboardSummaries;
use Illuminate\Support\Carbon;

/**
 * The overdue invoices card on the Material dashboard.
 *
 * Sales invoices only, issued or part-paid and past their due date — the ones somebody can chase. Credit
 * notes are left out for the reason the headline "unpaid" stat gives: a credit is owed to the customer.
 */
final class InvoicingDashboard
{
    public static function register(): void
    {
        DashboardSummaries::register(
            'invoicing.overdue',
            visible: fn (): bool => modules()->enabled('invoicing') && (bool) auth()->user()?->can('InvoiceView'),
            value: fn (): array => [
                'label' => __('Invoices overdue'),
                'value' => (string) Invoice::query()
                    ->where('kind', Invoice::KIND_SALE)
                    ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
                    ->whereDate('due_date', '<', Carbon::today()->toDateString())
                    ->count(),
                'link' => __('View invoices'),
                'url' => rescue(fn (): string => InvoiceResource::getUrl('index'), null, false),
                'tone' => 'error',
            ],
            sort: 30,
        );
    }
}
