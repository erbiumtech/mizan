<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Widgets\RevenueAndExpensesChart;
use App\Modules\Core\Filament\Pages\Dashboard;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\DashboardLayout;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Widgets\HeadcountOverview;
use App\Modules\Inventory\Filament\Widgets\StockOnHandOverview;
use App\Modules\Invoicing\Filament\Widgets\LargestDebtorsList;
use App\Modules\Support\Filament\Widgets\SlaComplianceOverview;
use App\Support\ModuleMap;
use App\Support\Reporting\DashboardArrangement;
use Filament\Facades\Filament;
use Filament\Widgets\WidgetConfiguration;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Per-user dashboard layouts — `docs/reports-expansion-plan.md` Phase 7.
 *
 * The plan asks for three guards by name (item 7) and each is here, but they are not equally important:
 *
 *  - **a new widget appears for somebody who has a saved layout**, which is item 1's regression and the one
 *    that decides whether this feature is liked or hated. "The person who arranged their dashboard is the
 *    person who never sees a new chart" is what a stored list of widgets produces, and there is no symptom —
 *    the dashboard looks fine, it is simply missing something nobody knows to look for;
 *  - **a widget whose module is disabled stays absent even when a layout names it**, which is item 4. Note
 *    what makes this pass: the resolver builds its list from the *panel* and only ever consults the layout as
 *    a lookup on it, so there is no code path in which a stored key could summon a widget. That is stronger
 *    than filtering afterwards, because filtering can be forgotten;
 *  - **a reset restores the company default**, which is item 2's other half.
 *
 * And two the plan does not name, added because they are the ways this breaks quietly. A layout must not
 * reveal a widget a *permission* refuses — the module half is the obvious one and the permission half is the
 * one that leaks figures rather than features. And a stored key for something that no longer exists must be
 * dropped on read, because a row outlives the code that wrote it.
 */
class DashboardLayoutTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** Three widgets from three modules, which is enough to have an order worth changing. */
    private const WIDGETS = [
        RevenueAndExpensesChart::class,
        HeadcountOverview::class,
        StockOnHandOverview::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'arranger@test.local'));
        $this->setCurrentTenant();
        $this->licenseEverything();
    }

    // ─────────────────────────────── what a layout is ──

    /** With no layout at all, the order is the one Phase 5.7 gave the bands. */
    public function test_with_no_layout_the_order_is_the_panels_own(): void
    {
        $this->assertSame(
            [RevenueAndExpensesChart::class, HeadcountOverview::class, StockOnHandOverview::class],
            $this->resolve(),
            'money, then people, then inventory — the bands in DashboardWidgets order',
        );
    }

    /** A stored order is applied. */
    public function test_a_stored_order_is_applied(): void
    {
        $this->layout(['order' => [
            $this->alias(StockOnHandOverview::class),
            $this->alias(RevenueAndExpensesChart::class),
        ]]);

        $this->assertSame(
            [StockOnHandOverview::class, RevenueAndExpensesChart::class, HeadcountOverview::class],
            $this->resolve(),
        );
    }

    /**
     * **A widget the layout does not mention still appears** — item 1, and the regression that matters.
     *
     * There is no need to register a new widget to test this, and registering one would be testing the panel
     * rather than the resolver: to a stored layout, a widget added last week and a widget it simply never
     * mentioned are the same thing, because the state has no way to tell them apart. Which is the whole point
     * of storing an order rather than a list — the layout below names two of three widgets and the third is
     * not a widget it has ever heard of.
     */
    public function test_a_widget_the_layout_does_not_mention_still_appears(): void
    {
        $this->layout(['order' => [
            $this->alias(StockOnHandOverview::class),
            $this->alias(RevenueAndExpensesChart::class),
        ]]);

        $resolved = $this->resolve();

        $this->assertContains(HeadcountOverview::class, $resolved, 'a widget the layout never named has vanished');
        $this->assertSame(HeadcountOverview::class, end($resolved), 'and it belongs after the ones it does name');
    }

    /**
     * Two unmentioned widgets keep their own order rather than the order they were handed in.
     *
     * `[position, sort]` as a pair, so the answer does not depend on how the panel happened to enumerate
     * them — a sort applied to a list already in the right order proves nothing.
     */
    public function test_unmentioned_widgets_keep_band_order_among_themselves(): void
    {
        $this->layout(['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->assertSame(
            [StockOnHandOverview::class, RevenueAndExpensesChart::class, HeadcountOverview::class],
            $this->resolve(array_reverse(self::WIDGETS)),
        );
    }

    /** A hidden widget is not rendered. */
    public function test_a_hidden_widget_is_not_rendered(): void
    {
        $this->layout(['hidden' => [$this->alias(HeadcountOverview::class)]]);

        $this->assertSame([RevenueAndExpensesChart::class, StockOnHandOverview::class], $this->resolve());
    }

    /**
     * But it is still in the arranger's list, because that is the only way back.
     *
     * A list that omitted hidden widgets would make hiding a one-way door — and the person who discovers that
     * is the person who hid the chart they wanted.
     */
    public function test_a_hidden_widget_is_still_listed_for_arranging(): void
    {
        $this->layout(['hidden' => [$this->alias(HeadcountOverview::class)]]);

        $rows = collect(DashboardArrangement::rows(self::WIDGETS, DashboardLayout::inForce()))
            ->keyBy('alias');

        $this->assertTrue($rows[$this->alias(HeadcountOverview::class)]['hidden']);
        $this->assertFalse($rows[$this->alias(StockOnHandOverview::class)]['hidden']);
        $this->assertCount(3, $rows);
    }

    // ─────────────────────────────── what a layout cannot do ──

    /**
     * **A layout cannot reveal a widget whose module is switched off** — item 4.
     *
     * The layout below names it first, which is the only interesting case: an order is applied by position,
     * so a bug here would put the widget of a module the company does not have at the top of the page.
     */
    public function test_a_layout_cannot_reveal_a_widget_whose_module_is_off(): void
    {
        $this->layout(['order' => [$this->alias(StockOnHandOverview::class)]]);

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'inventory'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertNotContains(StockOnHandOverview::class, $this->resolve());

        $this->assertNotContains(
            $this->alias(StockOnHandOverview::class),
            array_column(DashboardArrangement::rows(self::WIDGETS, DashboardLayout::inForce()), 'alias'),
            'and it is not offered for arranging either — a row for a widget that cannot render',
        );
    }

    /**
     * **Nor one a permission refuses**, which is the half the plan does not name and the one that leaks
     * figures rather than features.
     *
     * Written without naming a widget or a permission: whatever an Administrator can see and an Employee
     * cannot is the case under test, so this keeps testing the right thing as roles change. It fails loudly if
     * that set is ever empty, because then it would be asserting nothing.
     */
    public function test_a_layout_cannot_reveal_a_widget_a_permission_refuses(): void
    {
        $everything = $this->resolve();

        $this->actingAs($this->makeUser('Employee', 'employee@test.local'));

        $allowed = $this->resolve();
        $refused = array_values(array_diff($everything, $allowed));

        $this->assertNotEmpty($refused, 'no widget on this dashboard is refused to an Employee, so this test asserts nothing');

        // Named first in their own layout, which is as hard as this case gets.
        $this->layout(['order' => array_map(fn (string $class): string => $this->alias($class), $refused)]);

        $this->assertSame($allowed, $this->resolve(), 'a stored order handed somebody a widget their role cannot open');
    }

    /**
     * A stored key for a widget that no longer exists is dropped on read.
     *
     * Rows outlive code. A layout naming a deleted widget would otherwise keep a position in the order for
     * something that cannot render, and — worse — a width for it would reach the grid as a CSS value.
     */
    public function test_an_alias_for_no_widget_at_all_is_dropped(): void
    {
        $layout = $this->layout([
            'order' => ['App\\Filament\\Widgets\\WidgetThatWasDeleted', $this->alias(StockOnHandOverview::class)],
            'hidden' => ['App\\Filament\\Widgets\\WidgetThatWasDeleted'],
            'spans' => ['App\\Filament\\Widgets\\WidgetThatWasDeleted' => DashboardArrangement::FULL],
        ]);

        $this->assertSame([$this->alias(StockOnHandOverview::class)], $layout->order());
        $this->assertSame([], $layout->hidden());
        $this->assertSame([], $layout->spans());
    }

    /**
     * A width nothing recognises is dropped too, and the widget keeps its own.
     *
     * The one that would otherwise reach `gridColumn()` and be rendered as `span two-thirds / span
     * two-thirds` — a style declaration the browser ignores, so the widget would silently be one column wide.
     */
    public function test_a_width_that_is_not_one_of_the_three_is_dropped(): void
    {
        $layout = $this->layout(['spans' => [$this->alias(StockOnHandOverview::class) => 'two-thirds']]);

        $this->assertSame([], $layout->spans());
    }

    /**
     * **But an alias whose module is merely switched off is kept**, which is the deliberate exception.
     *
     * Sanitising is about keys that name nothing, not about keys that are inconvenient this week. A company
     * that pauses a module for a fortnight must not come back to everybody's arrangement of it erased.
     */
    public function test_an_alias_whose_module_is_off_keeps_its_place_in_the_state(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'inventory'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $layout = $this->layout(['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->assertSame([$this->alias(StockOnHandOverview::class)], $layout->order());
    }

    // ─────────────────────────────── widths ──

    /** Three widths, and the spans they map to in a six-column grid. */
    public function test_the_three_widths_map_to_spans(): void
    {
        $this->assertSame(6, DashboardArrangement::COLUMNS);
        $this->assertSame(3, DashboardArrangement::span(DashboardArrangement::HALF));
        $this->assertSame(4, DashboardArrangement::span(DashboardArrangement::TWO_THIRDS));

        // The keyword, not 6: Filament renders `full` as `1 / -1`, so a widget somebody made full width stays
        // full width if this grid is ever widened.
        $this->assertSame('full', DashboardArrangement::span(DashboardArrangement::FULL));
    }

    /**
     * A widget nobody has resized keeps the width it declares, translated.
     *
     * Which is what made the grid safe to widen from two columns to six: `$columnSpan = 1` meant half of two
     * and now means three of six, and `'full'` never meant a number at all.
     */
    public function test_a_widget_gets_its_own_width_when_nobody_chose_one(): void
    {
        $this->assertSame(DashboardArrangement::FULL, DashboardArrangement::defaultWidth(RevenueAndExpensesChart::class));

        // A `1` inherited from `Filament\Widgets\Widget` — which is what most of the custom widgets on this
        // dashboard have, while every stats overview is `'full'` from `StatsOverviewWidget`.
        $this->assertSame(DashboardArrangement::HALF, DashboardArrangement::defaultWidth(LargestDebtorsList::class));
    }

    /** A chosen width reaches the widget as a column span. */
    public function test_a_chosen_width_reaches_the_widget(): void
    {
        $this->layout(['spans' => [$this->alias(HeadcountOverview::class) => DashboardArrangement::TWO_THIRDS]]);

        $configured = collect(DashboardArrangement::widgets(self::WIDGETS, DashboardLayout::inForce()))
            ->keyBy(fn (WidgetConfiguration $configuration): string => $configuration->widget);

        $this->assertSame(
            DashboardArrangement::TWO_THIRDS,
            $configured[HeadcountOverview::class]->getProperties()['dashboardSpan'],
        );

        $widget = Livewire::test(HeadcountOverview::class, ['dashboardSpan' => DashboardArrangement::TWO_THIRDS])
            ->assertSuccessful()
            ->instance();

        $this->assertSame(4, $widget->getColumnSpan());
    }

    /**
     * A widget rendered anywhere but the arranged dashboard keeps the span it declares.
     *
     * The fallback is load-bearing rather than defensive: a widget mounted on a resource page, or directly in
     * a test, is in a two-column grid and would be a sixth of a page wide if it were handed a dashboard width.
     */
    public function test_a_widget_outside_the_dashboard_keeps_its_own_span(): void
    {
        $this->assertSame(
            1,
            Livewire::test(LargestDebtorsList::class)->assertSuccessful()->instance()->getColumnSpan(),
        );

        $this->assertSame(
            'full',
            Livewire::test(RevenueAndExpensesChart::class)->assertSuccessful()->instance()->getColumnSpan(),
        );
    }

    /** A nonsense width is ignored rather than passed on. */
    public function test_a_nonsense_width_leaves_the_widget_alone(): void
    {
        $widget = Livewire::test(LargestDebtorsList::class, ['dashboardSpan' => 'enormous'])
            ->assertSuccessful()
            ->instance();

        $this->assertSame(1, $widget->getColumnSpan());
    }

    // ─────────────────────────────── labels ──

    /**
     * Names are derived from class names, with acronyms put back.
     *
     * Derived rather than tabulated for the reason `DashboardWidgets` gives about not being a registry of
     * widget names: a table of twenty-three labels is a second place to update when a widget is added, and the
     * one that will be forgotten.
     */
    public function test_labels_read_as_english(): void
    {
        $this->assertSame('Revenue and expenses chart', DashboardArrangement::labelFor(RevenueAndExpensesChart::class));
        $this->assertSame('Headcount overview', DashboardArrangement::labelFor(HeadcountOverview::class));

        // The reason acronyms are handled at all: `Str::headline` gives "Sla Compliance Overview", which reads
        // as somebody's name.
        $this->assertSame('SLA compliance overview', DashboardArrangement::labelFor(SlaComplianceOverview::class));
    }

    // ─────────────────────────────── mine, and the company's ──

    /** A personal layout beats the company default. */
    public function test_a_personal_layout_beats_the_company_default(): void
    {
        DashboardLayout::put(null, ['order' => [$this->alias(HeadcountOverview::class)]]);
        DashboardLayout::put(auth()->id(), ['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->assertSame(StockOnHandOverview::class, $this->resolve()[0]);
    }

    /** And the company default applies to somebody who has none of their own. */
    public function test_the_company_default_applies_to_everybody_else(): void
    {
        DashboardLayout::put(null, ['order' => [$this->alias(HeadcountOverview::class)]]);

        $this->actingAs($this->makeUser('Administrator', 'somebody-else@test.local'));

        $this->assertSame(HeadcountOverview::class, $this->resolve()[0]);
    }

    /** One layout per person, however many times they rearrange. */
    public function test_saving_again_replaces_rather_than_adds(): void
    {
        DashboardLayout::put(auth()->id(), ['order' => [$this->alias(HeadcountOverview::class)]]);
        DashboardLayout::put(auth()->id(), ['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->assertSame(1, DashboardLayout::query()->count());
        $this->assertSame([$this->alias(StockOnHandOverview::class)], DashboardLayout::mine()->order());
    }

    /**
     * One company default, despite SQL.
     *
     * Both MySQL and SQLite treat NULLs as distinct in a unique index, so the index on `user_id` does not
     * enforce this — `updateOrCreate` does, because Eloquent turns a null there into `whereNull`. Worth a test
     * precisely because the index looks like it is doing the work.
     */
    public function test_there_is_only_ever_one_company_default(): void
    {
        DashboardLayout::put(null, ['order' => [$this->alias(HeadcountOverview::class)]]);
        DashboardLayout::put(null, ['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->assertSame(1, DashboardLayout::query()->whereNull('user_id')->count());
    }

    /**
     * **A reset restores the company default** — item 7's third guard.
     *
     * By deleting the personal row rather than copying the default into it, which is what makes reset mean
     * *follow* the default: somebody who resets today still moves with it if an administrator changes it
     * tomorrow. Asserted by changing the default afterwards.
     */
    public function test_a_reset_restores_the_company_default_and_keeps_following_it(): void
    {
        DashboardLayout::put(null, ['order' => [$this->alias(HeadcountOverview::class)]]);
        DashboardLayout::put(auth()->id(), ['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->arranging()->callAction('resetArrangement');

        $this->assertNull(DashboardLayout::mine());
        $this->assertSame(HeadcountOverview::class, $this->resolve()[0]);

        DashboardLayout::put(null, ['order' => [$this->alias(StockOnHandOverview::class)]]);

        $this->assertSame(StockOnHandOverview::class, $this->resolve()[0], 'the reset copied the default instead of following it');
    }

    /** Applying the default to everybody discards personal layouts and only those. */
    public function test_applying_the_default_to_everybody_discards_personal_layouts(): void
    {
        $other = User::factory()->create(['status' => 1]);

        DashboardLayout::put(null, ['order' => [$this->alias(HeadcountOverview::class)]]);
        DashboardLayout::put(auth()->id(), ['order' => [$this->alias(StockOnHandOverview::class)]]);
        DashboardLayout::put($other->getKey(), ['order' => [$this->alias(RevenueAndExpensesChart::class)]]);

        $this->arranging()->callAction('applyToEveryone');

        $this->assertSame(1, DashboardLayout::query()->count());
        $this->assertNotNull(DashboardLayout::companyDefault());
        $this->assertSame(HeadcountOverview::class, $this->resolve()[0]);
    }

    /** And it is an administrator's action, nobody else's. */
    public function test_only_an_administrator_is_offered_the_company_actions(): void
    {
        $this->arranging()
            ->assertActionExists('setCompanyDefault')
            ->assertActionExists('applyToEveryone')
            ->assertSee('Make this the company default');

        $this->actingAs($this->makeUser('Employee', 'not-an-admin@test.local'));

        // Asserted on what is rendered rather than through `assertActionDoesNotExist`, which reaches for a
        // `<name>Action()` method on the page when it cannot resolve the name and dies of the wrong error.
        // What matters here is what the person is offered, and this says exactly that: the arranging header is
        // there — they arrange their own dashboard like everybody else — and the company's two are not.
        $this->arranging()
            ->assertSee('Done')
            ->assertDontSee('Make this the company default')
            ->assertDontSee('Apply the default to everybody');
    }

    // ─────────────────────────────── the page ──

    /** Dragging a card stores a personal layout, not the company's. */
    public function test_reordering_stores_a_personal_layout(): void
    {
        Livewire::test(Dashboard::class)
            ->call('reorderWidgets', [$this->alias(StockOnHandOverview::class), $this->alias(HeadcountOverview::class)])
            ->assertSuccessful();

        $this->assertNull(DashboardLayout::companyDefault(), 'an administrator dragging a card changed the company default');
        $this->assertSame(
            [$this->alias(StockOnHandOverview::class), $this->alias(HeadcountOverview::class)],
            DashboardLayout::mine()->order(),
        );
    }

    /**
     * Two drops in one pass, and the second sees the first.
     *
     * The memo on the page is what makes one query per render possible, and a memo that outlived a write would
     * draw the arrangement as it was before the drop — Phase 5.8's mistake in a different costume. Livewire
     * mutates and re-renders inside the same request, so this is not a hypothetical.
     */
    public function test_reordering_twice_in_one_pass_is_not_stale(): void
    {
        $page = Livewire::test(Dashboard::class);

        $page->call('reorderWidgets', [$this->alias(StockOnHandOverview::class)]);
        $page->call('reorderWidgets', [$this->alias(HeadcountOverview::class), $this->alias(StockOnHandOverview::class)]);

        $this->assertSame(
            [$this->alias(HeadcountOverview::class), $this->alias(StockOnHandOverview::class)],
            DashboardLayout::mine()->order(),
        );

        $this->assertSame(HeadcountOverview::class, $page->instance()->getWidgets()[0]->widget);
    }

    /**
     * A read, then a write, then a read — in one request.
     *
     * Two memos are cleared on every write and they fail differently, so both are worth a test of their own.
     * This is the *rendered arrangement*: something resolves the widgets, an action then moves one, and the
     * render that follows must not be the list built a moment before the change. Driven through the page
     * instance rather than through `call()` on purpose — each `call()` is its own request, which is precisely
     * the case this memo is safe in and therefore the case that proves nothing.
     */
    public function test_the_arrangement_is_rebuilt_after_a_write_in_the_same_request(): void
    {
        $page = Livewire::test(Dashboard::class)->instance();

        $before = $page->getWidgets();
        $this->assertNotSame(StockOnHandOverview::class, $before[0]->widget, 'this widget already sorts first, so moving it proves nothing');

        $page->reorderWidgets([$this->alias(StockOnHandOverview::class)]);

        $this->assertSame(StockOnHandOverview::class, $page->getWidgets()[0]->widget, 'the page rendered the arrangement it had before the change');
    }

    /**
     * Two different changes in one pass compose rather than overwrite.
     *
     * The other half of the memo being cleared, and the one that hurts: hiding a widget and then dragging a
     * card are separate writes, and if the second read a state from before the first, the hide would be
     * silently undone by the reorder. The test above proves the *rendering* is not stale; this proves the
     * *state* is not.
     */
    public function test_two_changes_in_one_pass_compose(): void
    {
        $page = Livewire::test(Dashboard::class);

        $page->call('toggleWidget', $this->alias(HeadcountOverview::class));
        $page->call('reorderWidgets', [$this->alias(StockOnHandOverview::class)]);

        $mine = DashboardLayout::mine();

        $this->assertSame([$this->alias(HeadcountOverview::class)], $mine->hidden(), 'the reorder undid the hide');
        $this->assertSame([$this->alias(StockOnHandOverview::class)], $mine->order());
    }

    /**
     * Departing from the company default is an adjustment to it, not a fresh start.
     *
     * Somebody who moves one card should not silently lose everything an administrator arranged — so the state
     * a mutation starts from is whatever is in force, which for most people is the default.
     */
    public function test_moving_one_card_keeps_the_rest_of_the_company_default(): void
    {
        DashboardLayout::put(null, [
            'hidden' => [$this->alias(HeadcountOverview::class)],
            'spans' => [$this->alias(StockOnHandOverview::class) => DashboardArrangement::FULL],
        ]);

        Livewire::test(Dashboard::class)->call('reorderWidgets', [$this->alias(StockOnHandOverview::class)]);

        $mine = DashboardLayout::mine();

        $this->assertSame([$this->alias(HeadcountOverview::class)], $mine->hidden());
        $this->assertSame([$this->alias(StockOnHandOverview::class) => DashboardArrangement::FULL], $mine->spans());
    }

    /**
     * Arranging while a module is off does not erase that module's widgets from the layout.
     *
     * The arranger lists what somebody can see, so a drop sends back a list with the off-module widget missing
     * — and taking that list as the whole truth would quietly delete it. It keeps a place at the end instead,
     * which is the same treatment a widget added since the layout was saved gets.
     */
    public function test_arranging_while_a_module_is_off_keeps_its_widgets_in_the_layout(): void
    {
        $this->layout(['order' => [
            $this->alias(StockOnHandOverview::class),
            $this->alias(HeadcountOverview::class),
        ]]);

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'inventory'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        Livewire::test(Dashboard::class)->call('reorderWidgets', [$this->alias(HeadcountOverview::class)]);

        $this->assertSame(
            [$this->alias(HeadcountOverview::class), $this->alias(StockOnHandOverview::class)],
            DashboardLayout::mine()->order(),
            'a widget nobody could see while arranging was dropped from the layout',
        );
    }

    /** Hiding a widget, and bringing it back, are one control. */
    public function test_a_widget_can_be_hidden_and_brought_back(): void
    {
        $page = Livewire::test(Dashboard::class);

        $page->call('toggleWidget', $this->alias(HeadcountOverview::class));
        $this->assertSame([$this->alias(HeadcountOverview::class)], DashboardLayout::mine()->hidden());

        $page->call('toggleWidget', $this->alias(HeadcountOverview::class));
        $this->assertSame([], DashboardLayout::mine()->hidden());
    }

    /**
     * A width equal to the widget's own is stored as nothing at all.
     *
     * Item 1's "partial override" applied to widths: somebody who never widened a chart follows that chart's
     * own default if it ever changes, rather than being pinned to whatever it was the day they arranged their
     * dashboard.
     */
    public function test_choosing_a_widgets_own_width_stores_nothing(): void
    {
        $page = Livewire::test(Dashboard::class);

        $page->call('setWidgetWidth', $this->alias(LargestDebtorsList::class), DashboardArrangement::FULL);
        $this->assertSame(
            [$this->alias(LargestDebtorsList::class) => DashboardArrangement::FULL],
            DashboardLayout::mine()->spans(),
        );

        // Half is what this widget declares, so choosing it is choosing the default.
        $page->call('setWidgetWidth', $this->alias(LargestDebtorsList::class), DashboardArrangement::HALF);
        $this->assertSame([], DashboardLayout::mine()->spans());
    }

    /** An alias for nothing, or a width that is not a width, changes nothing. */
    public function test_a_width_for_an_unknown_widget_is_refused(): void
    {
        $page = Livewire::test(Dashboard::class);

        $page->call('setWidgetWidth', 'App\\Filament\\Widgets\\WidgetThatWasDeleted', DashboardArrangement::FULL);
        $page->call('setWidgetWidth', $this->alias(HeadcountOverview::class), 'enormous');

        $this->assertNull(DashboardLayout::mine(), 'a refused width still wrote a layout');
    }

    /** The arranger is not on the page until somebody asks for it. */
    public function test_the_arranger_appears_only_when_arranging(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSuccessful()
            ->assertDontSee('Drag to reorder')
            ->assertActionExists('arrange')
            ->assertActionDoesNotExist('doneArranging');

        $this->arranging()
            ->assertSee('Drag to reorder')
            ->assertSee(DashboardArrangement::labelFor(HeadcountOverview::class))
            ->assertActionExists('doneArranging');
    }

    /** Six columns, so that two thirds is a width the grid can express. */
    public function test_the_dashboard_grid_is_six_columns(): void
    {
        $this->assertSame(6, Livewire::test(Dashboard::class)->instance()->getColumns());
    }

    // ─────────────────────────────── helpers ──

    /**
     * The classes the dashboard would render, in order.
     *
     * @param  array<int, class-string>|null  $registered
     * @return array<int, class-string>
     */
    private function resolve(?array $registered = null): array
    {
        return array_map(
            fn (WidgetConfiguration $configuration): string => $configuration->widget,
            DashboardArrangement::widgets($registered ?? self::WIDGETS, DashboardLayout::inForce()),
        );
    }

    /**
     * The page with the arranger open.
     *
     * Mounted with `arranging` set rather than `->set('arranging', true)` on a page already mounted, and the
     * difference is not cosmetic: Filament caches a page's header actions when the component boots, so a
     * property set afterwards does not rebuild them and `doneArranging` is not there to find. In a browser
     * every property change is a fresh request and the question does not arise.
     */
    private function arranging(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Dashboard::class, ['arranging' => true])->assertSuccessful();
    }

    /** @param array<string, mixed> $state */
    private function layout(array $state): DashboardLayout
    {
        return DashboardLayout::put(auth()->id(), $state);
    }

    private function alias(string $class): string
    {
        return ModuleMap::alias($class);
    }

    /**
     * Every module on, because this is a test about arranging widgets and not about which exist.
     *
     * Gate::before is deliberately *not* used: two of these tests are about a permission refusing a widget,
     * and a blanket allow would remove the case under test. The Administrator role's real permissions are what
     * decide, which is what a company sees.
     */
    private function licenseEverything(): void
    {
        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
