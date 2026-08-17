<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Widgets\OperationsOverview;
use App\Modules\Core\Models\CompanyModule;
use App\Support\DashboardStats;
use Illuminate\Support\Facades\Gate;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The dashboard's overview shows what the installed modules contribute, and knows none of them.
 *
 * It used to live in Accounting and import Employees, Invoicing and Inventory to build four stats — a
 * dashboard widget being the reason three modules could not be packaged apart. Each module now registers
 * its own figure and the widget resolves whatever is there.
 *
 * The risk that swap introduces is silence: a registration deleted, or a provider that stops booting,
 * removes a figure from the dashboard and nothing else changes. Nobody notices a stat that is simply
 * absent — which is what these tests are for.
 */
class DashboardStatsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'stats@test.local'));
        $this->setCurrentTenant();
    }

    /** Every figure the widget used to build itself is still contributed by somebody. */
    public function test_each_module_contributes_its_own_stat(): void
    {
        $keys = DashboardStats::keys();

        foreach ([
            'employees.active',
            'accounting.pending-entries',
            'invoicing.unpaid',
            'inventory.reorder-level',
        ] as $key) {
            $this->assertContains($key, $keys, "nothing contributes [{$key}] — that figure has left the dashboard");
        }
    }

    /**
     * They resolve in a deliberate order.
     *
     * Discovery order is provider order, which is alphabetical by accident of `bootstrap/providers.php`;
     * the reading order of a dashboard is a decision. Asserted on the labels because that is what a
     * person sees.
     */
    public function test_the_stats_read_in_the_order_the_dashboard_intends(): void
    {
        $labels = array_map(
            fn ($stat): string => (string) $stat->getLabel(),
            DashboardStats::resolve(),
        );

        $this->assertSame([
            'Employees',
            'Journal Entries Awaiting Approval',
            'Unpaid Customer Invoices',
            'Products At / Below Reorder Level',
        ], $labels);
    }

    /** The widget renders them, which is the only thing the dashboard actually does with them. */
    public function test_the_widget_renders_the_contributed_stats(): void
    {
        \Livewire\Livewire::test(OperationsOverview::class)
            ->assertSuccessful()
            ->assertSee('Employees')
            ->assertSee('Unpaid Customer Invoices');
    }

    /**
     * A stat disappears with its module, rather than lingering as a nought.
     *
     * This is the behaviour the move bought, and it is better than what it replaced: the figure used to
     * be hidden by a permission check that happened to be false, which is a different thing from the
     * module not being there. `Modules::enabled()` gates the *widget* through `WidgetBelongsToModule`,
     * and the contribution itself goes when the provider does — which cannot be simulated in-process, so
     * what is asserted here is the observable half: an unlicensed module's figure is not shown.
     */
    public function test_a_disabled_module_takes_its_figure_off_the_dashboard(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'inventory'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        \Livewire\Livewire::test(OperationsOverview::class)
            ->assertSuccessful()
            ->assertDontSee('Products At / Below Reorder Level');
    }
}
