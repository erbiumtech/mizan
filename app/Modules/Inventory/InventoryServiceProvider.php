<?php

namespace App\Modules\Inventory;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Policies\ProductPolicy;
use App\Modules\Inventory\Policies\StockMovementPolicy;
use App\Support\DashboardStats;
use App\Support\JournalEntryOwners;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Explicit policy registration, because Laravel's App\Models\X -> App\Policies\XPolicy
 * guess cannot resolve a model that lives in a module directory, and Filament
 * treats a model with no policy as allowed. ModuleCoverageTest fails the build
 * if one is missing.
 */
class InventoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Product::class => ProductPolicy::class,
        StockMovement::class => StockMovementPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // A stock movement's journal entry belongs to the movement; editing it from the register would
        // leave the valuation and the ledger disagreeing. Registered here rather than named in
        // Accounting, which does not require Inventory.
        JournalEntryOwners::register('a stock movement', StockMovement::class);

        DashboardStats::register('inventory.reorder-level', function () {
            if (! auth()->user()?->can('ProductView')) {
                return null;
            }

            // On-hand via a single aggregate (withSum) — no per-product query.
            $low = Product::where('is_active', true)
                ->withSum('movements as on_hand_qty', 'quantity')
                ->get()
                ->filter(fn (Product $p): bool => (float) ($p->on_hand_qty ?? 0) <= (float) $p->reorder_level)
                ->count();

            return Stat::make('Products At / Below Reorder Level', $low);
        }, sort: 40);
    }
}
