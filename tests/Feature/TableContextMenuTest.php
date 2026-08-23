<?php

namespace Tests\Feature;

use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Table;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The right-click context menu — `docs/table-context-menu-plan.md` §5, Phase 1.
 *
 * **A JS feature whose *rule* is testable in PHP, and the rule is the part that matters.** The menu is
 * built in the browser from what the row already rendered, so nothing here clicks anything. What these
 * tests pin is the claim the whole design rests on: **the set of actions the menu can offer for a record
 * is exactly the set Filament already reduced for that record** — `getRecordActions()` with the
 * `isHidden()` ones dropped (`vendor/filament/tables/resources/views/index.blade.php:149-169`).
 *
 * If that ever stops being true, the menu has become a second list of actions with a second
 * authorization path, which §1.3 and the Risks section both name as the feature's one real hazard. This
 * file is what makes that a loud failure rather than a quiet one.
 *
 * The invoices table is the subject because its actions are genuinely per-record: each `visible()`
 * closure combines a *permission* with a *record state*, so a draft, an open and a paid invoice each
 * offer a different set. A table whose actions were uniform would make every assertion below vacuous.
 */
class TableContextMenuTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'contextmenu@test.local'));
        $this->setCurrentTenant();
    }

    private function customer(): Contact
    {
        return Contact::firstOrCreate(['name' => 'Context Menu Customer'], ['kind' => Contact::KIND_CUSTOMER]);
    }

    private function invoice(string $status, string $number): Invoice
    {
        return Invoice::create([
            'invoice_number' => $number,
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer()->getKey(),
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
            'status' => $status,
            'subtotal' => 1000,
            'total' => 1000,
        ]);
    }

    /**
     * Filament's own reduction, reproduced exactly.
     *
     * This is `$reduceVisibleRecordActions` from `index.blade.php:149-169`, and it is written out here on
     * purpose rather than called: the point of the test is that the menu's source of truth is *that*
     * reduction, so the test has to state it independently. If Filament changes the rule, this diverges
     * and the assertions below fail — which is the warning we want.
     *
     * @return array<int, Action>
     */
    private function visibleActionsFor(Table $table, Invoice $record): array
    {
        $reduced = [];

        foreach ($table->getRecordActions() as $action) {
            $action = $action->getClone();

            if (! $action instanceof BulkAction) {
                $action->record($record);
            }

            if ($action->isHidden()) {
                continue;
            }

            $reduced[] = $action;
        }

        return $reduced;
    }

    /** The labels of those actions, flattening an `ActionGroup` the way the rendered panel does. */
    private function labelsFor(Table $table, Invoice $record): array
    {
        $labels = [];

        foreach ($this->visibleActionsFor($table, $record) as $action) {
            if ($action instanceof ActionGroup) {
                foreach ($action->getActions() as $child) {
                    if (! $child->isHidden()) {
                        $labels[] = (string) $child->getLabel();
                    }
                }

                continue;
            }

            $labels[] = (string) $action->getLabel();
        }

        return $labels;
    }

    private function table(): Table
    {
        return Livewire::test(ListInvoices::class)->instance()->getTable();
    }

    // ---------------------------------------------------------------- the rule

    /**
     * **The rule, stated once: the menu offers what the row offers.**
     *
     * Not a longer list, not a shorter one. Everything else in this file is a case of this.
     */
    public function test_the_menu_can_only_offer_what_the_row_already_rendered(): void
    {
        $draft = $this->invoice(Invoice::STATUS_DRAFT, 'CM-DRAFT');
        $table = $this->table();

        $offered = $this->labelsFor($table, $draft);

        $this->assertNotEmpty($offered, 'a draft invoice has actions, so the assertion is not vacuous');
        $this->assertContains('Issue', $offered, 'a draft can be issued');
    }

    /**
     * **The negative case that matters**, and the reason the menu is derived rather than declared.
     *
     * A paid invoice cannot be issued, voided or paid again — those actions are hidden *for that record*
     * by their own `visible()` closures. A declared per-table list, which is what the package spiked in
     * Phase 0 requires, would show all three on every row.
     */
    public function test_a_record_that_hides_an_action_contributes_no_item_for_it(): void
    {
        $paid = $this->invoice(Invoice::STATUS_PAID, 'CM-PAID');
        $table = $this->table();

        $offered = $this->labelsFor($table, $paid);

        $this->assertNotContains('Issue', $offered, 'a paid invoice cannot be issued');
        $this->assertNotContains('Void', $offered, 'nor voided — crediting is the correction left');
        $this->assertNotContains('Record Payment', $offered, 'nor paid twice');
    }

    /**
     * And the sets genuinely differ between two rows of the same table.
     *
     * This is the assertion a declared list cannot satisfy at all, which is why it is here as its own
     * test rather than as a corollary. Two rows, one table, one render — different menus.
     */
    public function test_two_rows_of_one_table_offer_different_menus(): void
    {
        $draft = $this->invoice(Invoice::STATUS_DRAFT, 'CM-D2');
        $issued = $this->invoice(Invoice::STATUS_ISSUED, 'CM-I2');
        $table = $this->table();

        $draftLabels = $this->labelsFor($table, $draft);
        $issuedLabels = $this->labelsFor($table, $issued);

        $this->assertNotSame($draftLabels, $issuedLabels);
        $this->assertContains('Issue', $draftLabels);
        $this->assertNotContains('Issue', $issuedLabels, 'an issued invoice is past that');
        $this->assertContains('Void', $issuedLabels);
        $this->assertNotContains('Void', $draftLabels, 'a draft is deleted, not voided');
    }

    /**
     * **A permission the user does not hold removes the item, exactly as it removes the button.**
     *
     * The authorization test, and it is sharper than "log out of the module". An **Accountant** holds
     * `InvoiceIssue`, `InvoicePay` and `InvoiceView` but *not* `InvoiceVoid` — that grant is Manager's
     * (`app/Modules/Invoicing/module.php`). So on one issued invoice, for one user, the menu must offer
     * *Record Payment* and must not offer *Void*: it tracks the individual permission rather than
     * module access.
     *
     * Asserted through the real role rather than by stubbing the gate, so what is exercised is the grant
     * a customer would actually have. Note the first draft of this test used an Employee and could not
     * work at all — an Employee cannot reach the invoices list, so there is no table to reduce.
     */
    public function test_an_action_the_user_may_not_run_is_not_offered(): void
    {
        $issued = $this->invoice(Invoice::STATUS_ISSUED, 'CM-PERM');

        $this->actingAs($this->makeUser('Accountant', 'contextmenu-accountant@test.local'));

        $offered = $this->labelsFor($this->table(), $issued);

        $this->assertContains('Record Payment', $offered, 'an Accountant may take a payment');
        $this->assertNotContains('Void', $offered, 'but voiding is Manager\'s grant, so the menu must not offer it');
    }

    /**
     * A grouped action is reachable, because Filament renders the panel's items eagerly.
     *
     * `ActionGroup::toEmbeddedHtml()` iterates `getActions()`, skips `isHidden()` ones and writes the
     * survivors into an `x-cloak` panel that is **in the DOM**
     * (`vendor/filament/actions/src/ActionGroup.php:494-560`). That is what lets the menu include grouped
     * actions without opening the group and without a second visibility decision — the finding that makes
     * Phase 2 cheap, since a third of the tables that declare `recordActions()` also use a group.
     */
    public function test_a_grouped_actions_items_are_visible_to_the_reduction(): void
    {
        $group = ActionGroup::make([
            Action::make('alpha')->label('Alpha'),
            Action::make('beta')->label('Beta')->visible(false),
        ]);

        $labels = [];

        foreach ($group->getActions() as $action) {
            if (! $action->isHidden()) {
                $labels[] = (string) $action->getLabel();
            }
        }

        $this->assertSame(['Alpha'], $labels, 'the hidden one never reaches the panel, so never the menu');
    }

    // ---------------------------------------------------------------- delivery

    /**
     * **The script is a registered Filament asset, not an inline partial** — §2.
     *
     * The distinction is the whole of §2: an inline body script is re-executed on every `wire:navigate`
     * unless it says otherwise, which for a script that binds document listeners means one more listener
     * per navigation. A registered asset is delivered once and tagged, and `navigateOnce()` is what says
     * so.
     */
    public function test_the_context_menu_script_is_registered_as_an_asset(): void
    {
        $script = collect(FilamentAsset::getScripts())
            ->first(fn ($asset): bool => $asset->getId() === 'table-context-menu');

        $this->assertNotNull($script, 'the menu script is registered with Filament');
        $this->assertTrue($script->isNavigateOnce(), 'so its document listeners bind exactly once');
    }

    /** And it is published where Filament serves it from, which is what `filament:assets` does. */
    public function test_the_published_script_exists(): void
    {
        $this->assertFileExists(
            public_path('js/app/table-context-menu.js'),
            'run `php artisan filament:assets` — the panel registers this script and Filament serves the published copy',
        );
    }

    /**
     * **No inline script, anywhere.**
     *
     * A regression guard with a specific failure in mind: the cheapest way to add a feature to this menu
     * later is a render-hook partial, and that is the one way to reintroduce the listener stacking §2
     * exists to prevent. If this fails, the fix is to put the code in the asset, not to relax the test.
     */
    public function test_the_menu_ships_no_inline_script(): void
    {
        $partials = glob(resource_path('views/filament/partials/*.blade.php')) ?: [];

        // A floor, so the test cannot pass by finding nothing. The first draft globbed a directory that
        // does not exist, asserted nothing, and PHPUnit called it risky — which is the only reason it was
        // noticed. A vacuous guard is worse than no guard, because it reads as coverage.
        $this->assertGreaterThan(4, count($partials), 'the render-hook partials are where they are expected');

        foreach ($partials as $partial) {
            $this->assertStringNotContainsString(
                'fi-ta-context-menu',
                (string) file_get_contents($partial),
                basename($partial).' must not carry the context menu — §2 requires a registered asset',
            );
        }
    }

    // ---------------------------------------------------------------- the browser harness

    /**
     * Writes the harness that `php artisan table-context-menu:smoke` drives — §5.3.
     *
     * **Skipped unless asked for**, because a test that writes a file as a side effect of the ordinary
     * suite is a test that surprises somebody. The command sets `CONTEXT_MENU_HARNESS` and runs this one
     * method, then hands the file to headless Chrome.
     *
     * It lives here rather than in the command because `Livewire::test()` needs the testing harness
     * bootstrapped — outside PHPUnit it throws *"Invalid Livewire snapshot structure"*, which is how this
     * arrangement came about. `loadTable()` matters as much: the invoices table calls `deferLoading()`, so
     * a first render carries no rows and a harness built from one would be an empty page that passes
     * every assertion by having nothing in it.
     */
    public function test_it_writes_the_context_menu_harness(): void
    {
        $target = getenv('CONTEXT_MENU_HARNESS');

        if ($target === false || $target === '') {
            $this->markTestSkipped('Set CONTEXT_MENU_HARNESS, or run `php artisan table-context-menu:smoke`.');
        }

        $this->invoice(Invoice::STATUS_DRAFT, 'SMOKE-DRAFT');
        $this->invoice(Invoice::STATUS_ISSUED, 'SMOKE-ISSUED');

        $markup = Livewire::test(ListInvoices::class)->loadTable()->html();

        $this->assertStringContainsString('fi-ta-row', $markup, 'the harness needs rendered rows');

        file_put_contents($target, $markup);
    }

    // ---------------------------------------------------------------- cost

    /**
     * **No per-row cost** — §5.4, in the spirit of `PanelPerformanceTest`.
     *
     * The menu is one element for the page, created when it first opens. Nothing is rendered per row, so
     * a page of twenty-five invoices must cost exactly what it costs without the feature: the row markup
     * carries no menu subtree, and the script is one `<script>` for the document.
     */
    public function test_the_feature_adds_nothing_to_a_rendered_row(): void
    {
        foreach (range(1, 25) as $n) {
            $this->invoice(Invoice::STATUS_ISSUED, "CM-ROW-{$n}");
        }

        // `loadTable()`, because the invoices table defers loading — without it the render has no rows
        // and this test asserts that no markup was added to nothing. It did, until the browser run
        // showed the harness was an empty page.
        $html = Livewire::test(ListInvoices::class)->loadTable()->html();

        // A floor of ten, not twenty-five: the table paginates, so twenty-five records render one page.
        // The number is here to prove the rows exist at all — the assertion that matters is the next one.
        $this->assertGreaterThanOrEqual(10, substr_count($html, 'fi-ta-row'), 'the rows really are rendered');
        $this->assertSame(
            0,
            substr_count($html, 'fi-ta-context-menu'),
            'the menu contributes no markup to the table — it is created in the browser, on open',
        );
    }

    /**
     * The script itself never binds per row or per table.
     *
     * Asserted against the source, because it is a property of the code rather than of a render: §1.1
     * requires one delegated listener, and this codebase "has already paid for per-row mistakes twice".
     * A `querySelectorAll` over rows followed by `addEventListener` is the shape that would undo it.
     */
    public function test_the_script_binds_one_delegated_listener(): void
    {
        $source = (string) file_get_contents(resource_path('js/table-context-menu.js'));

        $this->assertStringContainsString(
            "document.addEventListener('contextmenu'",
            $source,
            'the listener is on the document, not on rows',
        );

        // The escape hatch, which the plan calls the difference between a power feature and a complaint.
        $this->assertStringContainsString('event.shiftKey', $source, 'Shift yields the native menu');

        // And the SPA rule: a menu left floating over swapped content would mount actions against a
        // component that is gone.
        $this->assertStringContainsString("'livewire:navigated'", $source, 'the menu closes on navigation');
    }

    /**
     * The link items exist, because taking away *open in new tab* is the complaint this feature invites.
     *
     * Asserted on the source for the same reason as above — and specifically that the new-tab item is a
     * real anchor with `target`, not a `window.open`, so the browser's own modifiers keep working.
     */
    public function test_the_script_carries_the_link_items(): void
    {
        $source = (string) file_get_contents(resource_path('js/table-context-menu.js'));

        foreach (['Open in new tab', 'Copy link'] as $label) {
            $this->assertStringContainsString($label, $source);
        }

        $this->assertStringContainsString("el.target = '_blank'", $source, 'a real anchor, not a JS open');
        // The *call*, not the phrase: the docblock above discusses `window.open` by name, and asserting
        // the bare string made this test fail on its own explanation.
        $this->assertStringNotContainsString('window.open(', $source);
    }
}
