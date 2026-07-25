<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Services\InventoryValuationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Operational value metrics from the Nova dashboard (Active Employees,
 * Pending Journal Entries, Unpaid Invoices, Low Stock Products). Each stat
 * mirrors a Nova card; individual permission gating is applied per stat by
 * omitting stats the user may not see.
 */
class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('EmployeeView')
            || $user?->can('JournalEntryApprove')
            || $user?->can('InvoiceView')
            || $user?->can('ProductView'));
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        if ($user?->can('EmployeeView')) {
            $stats[] = Stat::make('Employees', Employee::where('is_active', 1)->count())
                ->description('active');
        }

        if ($user?->can('JournalEntryApprove')) {
            $stats[] = Stat::make(
                'Journal Entries Awaiting Approval',
                JournalEntry::where('status', JournalEntry::STATUS_PENDING)->count()
            )->description('pending');
        }

        if ($user?->can('InvoiceView')) {
            $open = Invoice::where('kind', Invoice::KIND_SALE)
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
                ->get();

            $stats[] = Stat::make(
                'Unpaid Customer Invoices',
                'PKR '.number_format(round($open->sum(fn (Invoice $i) => $i->outstanding()), 2), 2)
            )->description($open->count().' open');
        }

        if ($user?->can('ProductView')) {
            $valuation = app(InventoryValuationService::class);

            $low = Product::where('is_active', true)
                ->get()
                ->filter(fn (Product $p) => $valuation->onHand($p) <= (float) $p->reorder_level)
                ->count();

            $stats[] = Stat::make('Products At / Below Reorder Level', $low);
        }

        return $stats;
    }
}
