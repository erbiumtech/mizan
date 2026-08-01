<?php

namespace App\Modules\Accounting\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Inventory\Models\Product;
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
    use WidgetBelongsToModule;

    protected static bool $isLazy = true;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

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
            // Single aggregate query instead of loading every open invoice.
            $open = Invoice::where('kind', Invoice::KIND_SALE)
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total - amount_paid), 0) as outstanding_total')
                ->first();

            $stats[] = Stat::make(
                'Unpaid Customer Invoices',
                'PKR '.number_format(round((float) $open->outstanding_total, 2), 2)
            )->description(((int) $open->cnt).' open');
        }

        if ($user?->can('ProductView')) {
            // On-hand via a single aggregate (withSum) — no per-product query.
            $low = Product::where('is_active', true)
                ->withSum('movements as on_hand_qty', 'quantity')
                ->get()
                ->filter(fn (Product $p) => (float) ($p->on_hand_qty ?? 0) <= (float) $p->reorder_level)
                ->count();

            $stats[] = Stat::make('Products At / Below Reorder Level', $low);
        }

        return $stats;
    }
}
