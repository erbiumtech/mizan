<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\SavedReportView;
use App\Modules\Core\Models\User;
use App\Support\Reporting\ReportComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Saved views — `docs/reports-expansion-plan.md` Phase 4.5.
 *
 * "The filters somebody uses every month, kept. The URL already carries the whole state, so this is storage
 * rather than plumbing."
 *
 * The tests are mostly about the three things a saved view must **not** do, because each is a way for stored
 * state to quietly mislead:
 *
 *  - **it must not store the date.** What somebody uses every month is the filters; the date is the thing
 *    that changes every month, and a view holding 30 June would open on 30 June for ever with nobody
 *    noticing for a while. A fixed date already has a better mechanism — the URL.
 *  - **it must not clear a filter it does not carry.** The absence of a stored account is not an instruction
 *    to blank an account somebody has since picked.
 *  - **it must not reach another person's or another report's views**, which the model's own scope enforces
 *    rather than each caller remembering to.
 *
 * The report throughout is **Find Transactions**, and that is not arbitrary: it is one of the eight reports
 * that have anything to save. A saved view holds a comparison basis or one of the four pickers, so the other
 * forty-three reports — a date and nothing else — have nothing to remember, and the pane does not offer to
 * remember it. An earlier draft of these tests used the aged receivables and passed every assertion about the
 * model while the control was correctly absent from the page.
 */
class SavedReportViewTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['status' => 1]);
        $this->actingAs($this->me);
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    // ───────────────────────────────── what a view stores ──

    /** Saving keeps the filters under a name. */
    public function test_a_view_keeps_the_filters_under_a_name(): void
    {
        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->set('find', 'Karachi')
            ->set('viewName', 'Karachi only')
            ->call('saveView');

        $view = SavedReportView::query()->mine()->first();

        $this->assertNotNull($view);
        $this->assertSame('Karachi only', $view->name);
        $this->assertSame('FindTransactions', $view->report_key);
        $this->assertSame('Karachi', $view->state['find']);
    }

    /**
     * **The date is never stored.** This is the phase's own point.
     *
     * A view holding 30 June would open on 30 June for ever, and the person who saved it would not notice for
     * a while — the worst kind of wrong on a report. For a fixed date the URL already carries the whole
     * state, which is 4c's premise: a link for a moment, a saved view for a habit.
     */
    public function test_a_view_never_stores_the_date(): void
    {
        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->set('viewName', 'Whatever')
            ->call('saveView');

        $state = SavedReportView::query()->mine()->first()->state;

        $this->assertArrayNotHasKey('asOf', $state);
        $this->assertNotContains('2027-02-20', $state);
    }

    /**
     * And nothing outside the allow-list, so a future property on the hub does not join by accident.
     *
     * The realistic failure this prevents: somebody adds a page property, saving starts storing it, and every
     * existing view silently begins applying something it never meant to.
     */
    public function test_a_view_stores_only_the_named_filters(): void
    {
        $stored = SavedReportView::filtered([
            'compare' => ReportComparison::PREVIOUS_MONTH,
            'find' => 'Karachi',
            'asOf' => '2027-02-20',
            'selected' => 'FindTransactions',
            'section' => 'Operations',
            'somethingNew' => 'surprise',
        ]);

        $this->assertSame(['compare', 'find'], array_keys($stored));
    }

    /** An empty filter is not a filter, so it is not stored. */
    public function test_an_empty_filter_is_not_stored(): void
    {
        $stored = SavedReportView::filtered([
            'compare' => ReportComparison::PREVIOUS_YEAR,
            'account' => null,
            'budget' => '',
            'find' => '',
            'month' => null,
        ]);

        $this->assertSame(['compare'], array_keys($stored));
    }

    /** A comparison basis is normalised on the way in, so a view cannot hold one the pane would refuse. */
    public function test_the_comparison_basis_is_normalised_on_save(): void
    {
        $stored = SavedReportView::filtered(['compare' => 'previous_fortnight']);

        $this->assertSame(ReportComparison::PREVIOUS_YEAR, $stored['compare']);
    }

    /**
     * And again on the way out, because a row can outlive a basis.
     *
     * A view saved when some basis briefly existed would otherwise hand the pane a string it has since
     * stopped recognising.
     */
    public function test_the_comparison_basis_is_normalised_on_read(): void
    {
        $view = SavedReportView::query()->create([
            'user_id' => $this->me->getKey(),
            'report_key' => 'BalanceSheet',
            'name' => 'Stale',
            'state' => ['compare' => 'previous_fortnight'],
        ]);

        $this->assertSame(ReportComparison::PREVIOUS_YEAR, $view->filters()['compare']);
    }

    // ───────────────────────────────── saving over a name ──

    /**
     * Saving again under the same name replaces it.
     *
     * Which is what somebody adjusting last month's filters means by pressing save — not "give me a second
     * view called the same thing".
     */
    public function test_saving_under_the_same_name_replaces_the_view(): void
    {
        $page = Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20']);

        $page->set('find', 'Karachi')->set('viewName', 'My view')->call('saveView');
        $page->set('find', 'Lahore')->set('viewName', 'My view')->call('saveView');

        $this->assertSame(1, SavedReportView::query()->mine()->count());
        $this->assertSame('Lahore', SavedReportView::query()->mine()->first()->state['find']);
    }

    /** A different name is a different view. */
    public function test_a_different_name_is_a_different_view(): void
    {
        $page = Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20']);

        $page->set('find', 'Karachi')->set('viewName', 'One')->call('saveView');
        $page->set('find', 'Lahore')->set('viewName', 'Two')->call('saveView');

        $this->assertSame(2, SavedReportView::query()->mine()->count());
    }

    /** An unnamed save does nothing rather than creating a view called nothing. */
    public function test_an_unnamed_save_does_nothing(): void
    {
        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->set('viewName', '   ')
            ->call('saveView');

        $this->assertSame(0, SavedReportView::query()->count());
    }

    /** Nor does a save with no report open. */
    public function test_a_save_with_nothing_selected_does_nothing(): void
    {
        Livewire::test(Reports::class, ['asOf' => '2027-02-20'])
            ->set('selected', null)
            ->set('viewName', 'Nothing')
            ->call('saveView');

        $this->assertSame(0, SavedReportView::query()->count());
    }

    /** The name box empties after a save, so the next one starts clean. */
    public function test_the_name_box_empties_after_saving(): void
    {
        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->set('viewName', 'Saved')
            ->call('saveView')
            ->assertSet('viewName', '');
    }

    // ───────────────────────────────── applying a view ──

    /** Applying a view puts its filters back. */
    public function test_applying_a_view_restores_its_filters(): void
    {
        $view = SavedReportView::put('FindTransactions', 'Karachi', [
            'find' => 'Karachi',
            'compare' => ReportComparison::PREVIOUS_MONTH,
        ]);

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->call('applyView', $view->getKey())
            ->assertSet('find', 'Karachi')
            ->assertSet('compare', ReportComparison::PREVIOUS_MONTH);
    }

    /**
     * Applying a view leaves the date alone.
     *
     * The other half of "a view does not store the date": it must not silently move somebody off the date
     * they are looking at either.
     */
    public function test_applying_a_view_leaves_the_date_alone(): void
    {
        $view = SavedReportView::put('FindTransactions', 'Karachi', ['find' => 'Karachi']);

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->call('applyView', $view->getKey())
            ->assertSet('asOf', '2027-02-20');
    }

    /**
     * A view does not clear a filter it does not carry.
     *
     * The absence of a stored account is not an instruction to blank an account somebody has since picked —
     * and a loop that assigned every possible filter would do exactly that.
     */
    public function test_applying_a_view_does_not_clear_a_filter_it_does_not_carry(): void
    {
        $view = SavedReportView::put('FindTransactions', 'Just a search', ['find' => 'Karachi']);

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->set('month', 'January')
            ->call('applyView', $view->getKey())
            ->assertSet('find', 'Karachi')
            ->assertSet('month', 'January');
    }

    // ───────────────────────── whose views, and which report's ──

    /** A view belongs to the person who saved it. */
    public function test_a_view_belongs_to_the_person_who_saved_it(): void
    {
        SavedReportView::put('FindTransactions', 'Mine', ['find' => 'Karachi']);

        $this->assertCount(1, SavedReportView::forReport('FindTransactions'));

        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->assertCount(0, SavedReportView::forReport('FindTransactions'));
    }

    /** And somebody else's view cannot be applied, whatever id arrives from the browser. */
    public function test_another_persons_view_cannot_be_applied(): void
    {
        $theirs = SavedReportView::put('FindTransactions', 'Theirs', ['find' => 'Secret']);

        $this->actingAs(User::factory()->create(['status' => 1]));

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->call('applyView', $theirs->getKey())
            ->assertSet('find', '');
    }

    /** Nor forgotten. */
    public function test_another_persons_view_cannot_be_forgotten(): void
    {
        $theirs = SavedReportView::put('FindTransactions', 'Theirs', ['find' => 'Secret']);

        $this->actingAs(User::factory()->create(['status' => 1]));

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->call('forgetView', $theirs->getKey());

        $this->assertSame(1, SavedReportView::query()->count(), "somebody else's view was deleted");
    }

    /** A view saved on one report does not appear on another. */
    public function test_a_view_is_scoped_to_its_own_report(): void
    {
        SavedReportView::put('FindTransactions', 'Karachi', ['find' => 'Karachi']);

        $this->assertCount(1, SavedReportView::forReport('FindTransactions'));
        $this->assertCount(0, SavedReportView::forReport('BalanceSheet'));
    }

    /** And cannot be applied from another report's pane. */
    public function test_a_view_cannot_be_applied_from_another_report(): void
    {
        $view = SavedReportView::put('FindTransactions', 'Karachi', ['find' => 'Karachi']);

        Livewire::test(Reports::class, ['selected' => 'BalanceSheet', 'asOf' => '2027-02-20'])
            ->call('applyView', $view->getKey())
            ->assertSet('find', '');
    }

    /** Forgetting removes it. */
    public function test_forgetting_removes_the_view(): void
    {
        $view = SavedReportView::put('FindTransactions', 'Karachi', ['find' => 'Karachi']);

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->call('forgetView', $view->getKey());

        $this->assertSame(0, SavedReportView::query()->count());
    }

    /** With nobody signed in there are no views, rather than everybody's. */
    public function test_there_are_no_views_for_nobody(): void
    {
        SavedReportView::put('FindTransactions', 'Mine', ['find' => 'Karachi']);

        auth()->logout();

        $this->assertCount(0, SavedReportView::forReport('FindTransactions'));
    }

    /** And none for no report. */
    public function test_there_are_no_views_for_no_report(): void
    {
        SavedReportView::put('FindTransactions', 'Mine', ['find' => 'Karachi']);

        $this->assertCount(0, SavedReportView::forReport(null));
    }

    // ───────────────────────────────── on the page ──

    /** The saved view's name is on the pane, ready to apply. */
    public function test_the_pane_lists_the_saved_views(): void
    {
        SavedReportView::put('FindTransactions', 'Karachi only', ['find' => 'Karachi']);

        Livewire::test(Reports::class, ['selected' => 'FindTransactions', 'asOf' => '2027-02-20'])
            ->assertSuccessful()
            ->assertSee('Karachi only');
    }
}
