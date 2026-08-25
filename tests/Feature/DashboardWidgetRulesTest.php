<?php

namespace Tests\Feature;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Support\Reporting\DashboardWidgets;
use Filament\Facades\Filament;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The rules every dashboard widget obeys — `docs/reports-expansion-plan.md` Phase 5.7.
 *
 * "**Every widget**: `WidgetBelongsToModule` plus its own `canView()` gating on module *and* permission;
 * `$isLazy = true` without exception, so the dashboard renders and the panels fill in; `$sort` set
 * deliberately so the order is money → sales → service → people rather than discovery order — and no polling."
 *
 * **A test rather than twenty-three careful edits.** Phase 5 added fourteen widgets and the nine that predate
 * it were on sorts 0–8 in discovery order with two of them not lazy. Fixing those is a commit; keeping them
 * fixed is this file. The next widget somebody adds fails here if it forgets any of the four.
 *
 * **And one guard that is not in the item, added because Phase 5.7 found what it catches.**
 * `OperationsOverview` — the company's headline figures, the one widget assembled from every module's
 * contributions — was registered *nowhere*. Core discovered resources and pages but never widgets. It went
 * unnoticed because the old `FilamentWidgetsSmokeTest` named it in a hand-written list and rendered it
 * directly, so the widget worked in the test and was absent from the product; and enumerating the panel
 * instead, which Phase 5.9 asks for, could not catch it either — an enumeration only sees what is registered.
 * The guard that finds it compares the widget *files* against the registered set.
 */
class DashboardWidgetRulesTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /**
     * Every widget the panel has registered.
     *
     * @return array<int, class-string>
     */
    private function widgets(): array
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return array_values(array_map(
            fn (mixed $widget): string => $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
            Filament::getWidgets(),
        ));
    }

    /**
     * Every widget class that exists on disk.
     *
     * @return array<int, class-string>
     */
    private function widgetFiles(): array
    {
        $classes = [];

        foreach (glob(app_path('Modules/*/Filament/Widgets/*.php')) as $file) {
            $module = basename(dirname($file, 3));
            $classes[] = 'App\\Modules\\'.$module.'\\Filament\\Widgets\\'.basename($file, '.php');
        }

        return $classes;
    }

    // ─────────────────── registered at all ──

    /**
     * Every widget on disk is registered with the panel.
     *
     * The guard that found `OperationsOverview` absent from the dashboard entirely. A widget nobody registered
     * is a widget that renders perfectly in a test and does not exist in the product, and no amount of
     * enumerating the panel will say so.
     */
    public function test_every_widget_file_is_registered_with_the_panel(): void
    {
        $missing = array_diff($this->widgetFiles(), $this->widgets());

        $this->assertSame(
            [],
            array_values(array_map('class_basename', $missing)),
            "these widgets exist but the panel has never heard of them — add discoverWidgets() to the module's plugin",
        );
    }

    /** And there are enough of them that this file is measuring something. */
    public function test_the_panel_has_a_plausible_number_of_widgets(): void
    {
        $this->assertGreaterThanOrEqual(20, count($this->widgets()));
    }

    // ─────────────────── the four rules ──

    /** Every widget uses `WidgetBelongsToModule`, which is what gives it a module to gate on. */
    public function test_every_widget_belongs_to_a_module(): void
    {
        foreach ($this->widgets() as $widget) {
            $this->assertContains(
                WidgetBelongsToModule::class,
                class_uses_recursive($widget),
                class_basename($widget).' does not use WidgetBelongsToModule',
            );

            // And the module it names is one that exists — `module()` throws otherwise, which is the trait's
            // own way of refusing a widget `ModuleMap` does not own.
            $this->assertNotSame('', $widget::module(), class_basename($widget).' names no module');
        }
    }

    /**
     * Every widget is lazy, without exception.
     *
     * The item's own wording, and the reason is the dashboard: twenty-three widgets rendered eagerly is
     * twenty-three sets of aggregates before the page appears. Two widgets predating Phase 5 were not lazy —
     * `CashFlowChart` and `PayrollByEmployeeChart` — and nothing said so.
     */
    public function test_every_widget_is_lazy(): void
    {
        foreach ($this->widgets() as $widget) {
            $this->assertTrue(
                (new ReflectionProperty($widget, 'isLazy'))->getValue(),
                class_basename($widget).' is not lazy',
            );
        }
    }

    /**
     * Nothing polls.
     *
     * A dashboard that re-runs every aggregate on a timer is a report running itself fifteen times an hour
     * per open tab, which is the cost Phase 5.8 is about avoiding rather than doubling.
     */
    public function test_no_widget_polls(): void
    {
        foreach ($this->widgets() as $widget) {
            if (! property_exists($widget, 'pollingInterval')) {
                continue;
            }

            $this->assertNull(
                (new ReflectionProperty($widget, 'pollingInterval'))->getValue(new $widget),
                class_basename($widget).' polls',
            );
        }
    }

    /**
     * Every widget's sort sits inside a band.
     *
     * Which is how "money → sales → service → people" is enforced rather than described: a sort of 8 belongs
     * to no band, and belonging to no band is exactly how discovery order gets back in.
     */
    public function test_every_widget_sorts_into_a_band(): void
    {
        foreach ($this->widgets() as $widget) {
            $sort = (new ReflectionProperty($widget, 'sort'))->getValue();

            $this->assertNotNull(
                DashboardWidgets::bandFor($sort),
                class_basename($widget)." has sort {$sort}, which is in no band — see App\\Support\\Reporting\\DashboardWidgets",
            );
        }
    }

    /**
     * No two widgets share a sort.
     *
     * Filament's order between equal sorts is discovery order, so a duplicate is the thing the item is against
     * wearing a number.
     */
    public function test_no_two_widgets_share_a_sort(): void
    {
        $sorts = [];

        foreach ($this->widgets() as $widget) {
            $sort = (new ReflectionProperty($widget, 'sort'))->getValue();
            $sorts[$sort][] = class_basename($widget);
        }

        $clashes = array_filter($sorts, fn (array $names): bool => count($names) > 1);

        $this->assertSame([], array_map(fn (array $n): string => implode(' / ', $n), $clashes));
    }

    /** The bands run in the plan's order: money, then sales, then service, then people. */
    public function test_the_bands_run_in_the_plans_order(): void
    {
        $this->assertSame(
            ['headline', 'money', 'sales', 'service', 'people', 'inventory'],
            array_keys(DashboardWidgets::bands()),
        );

        $floors = array_values(DashboardWidgets::bands());
        $sorted = $floors;
        sort($sorted);

        $this->assertSame($sorted, $floors, 'the bands are not in ascending order');
    }

    // ─────────────────── the gates ──

    /**
     * Every widget hides itself when its module is off.
     *
     * The module half of "gating on module *and* permission". Asserted for all of them at once, because a
     * widget that forgets it is one a company sees for a module it has not bought.
     */
    public function test_every_widget_hides_without_its_module(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['status' => 1, 'is_super_admin' => true]);
        $company->users()->attach($user->getKey());
        $this->actingAs($user);
        $this->setCurrentTenant($company);

        // Every module explicitly off, so no widget can be visible on module grounds.
        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => false, 'enabled' => false],
            );
        }
        modules()->flush();

        foreach ($this->widgets() as $widget) {
            // Core is always available by design, so its widget cannot be hidden this way — it gates on
            // whether anything was contributed instead, which with every module off is nothing.
            $this->assertFalse(
                $widget::canView(),
                class_basename($widget).' is visible with every module disabled',
            );
        }
    }

    /**
     * And every widget hides itself from a user holding no permissions.
     *
     * The permission half. A widget gating only on the module would show its figures to anybody who can reach
     * the dashboard, which for most of these is a report somebody may not open.
     */
    public function test_every_widget_hides_from_a_user_with_no_permissions(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $this->actingAs($user);
        $this->setCurrentTenant($company);

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        foreach ($this->widgets() as $widget) {
            $this->assertFalse(
                $widget::canView(),
                class_basename($widget).' is visible to a user with no permissions at all',
            );
        }
    }
}
