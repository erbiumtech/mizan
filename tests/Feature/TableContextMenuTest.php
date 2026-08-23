<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
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

    /**
     * **The published copy is what production serves, and it must be the source byte for byte.**
     *
     * `filament:assets` copies `resources/js/` into `public/js/app/`, and that copy is tracked in this
     * repository alongside Filament's own. Which means it can go stale — and did: Phase 1 published once,
     * then fixed the row selector twice, and the committed public copy was the version whose selector
     * matched **nothing**. Every test passed, the browser smoke run passed, and the feature would have
     * been silently inert in production, because all of them read `resources/js/` while the panel serves
     * `public/js/`.
     *
     * Asserting existence — which is all this test did — could not see that. Asserting equality can.
     * If it fails, run `php artisan filament:assets`.
     */
    public function test_the_published_script_is_the_source(): void
    {
        $source = resource_path('js/table-context-menu.js');
        $published = public_path('js/app/table-context-menu.js');

        $this->assertFileExists($published, 'run `php artisan filament:assets`');
        $this->assertSame(
            file_get_contents($source),
            file_get_contents($published),
            'The published script is stale. Production serves this copy, not the one in resources/ that '
            .'every other test reads — run `php artisan filament:assets`.',
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

    // ---------------------------------------------------------------- reach: every table

    /**
     * **The record key reaches the markup, and it agrees with Filament's own** — §3.
     *
     * The agreement is the point, not the presence. The script reads `data-record-key` first and parses
     * `wire:key` when there is none, so if the two ever disagreed the row identified would depend on
     * which path ran — and the bug would surface only on tables that lack the attribute. Asserting they
     * match is what makes one fallback safe rather than a second source of truth.
     */
    public function test_every_row_carries_a_record_key_matching_filaments_own(): void
    {
        $draft = $this->invoice(Invoice::STATUS_DRAFT, 'CM-KEY');

        $html = Livewire::test(ListInvoices::class)->loadTable()->html();

        $this->assertStringContainsString('data-record-key="'.$draft->getKey().'"', $html);

        // Filament's own key for the row, from the `wire:key` the script falls back to parsing.
        preg_match('/wire:key="[^"]*\.table\.records\.([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches, 'the row carries a wire:key for the fallback to parse');
        $this->assertSame(
            (string) $draft->getKey(),
            $matches[1],
            'the attribute and the wire:key must name the same record',
        );
    }

    /**
     * **No table file was edited to gain the menu**, which is the whole value of §3.
     *
     * One global `Table::configureUsing()` rather than 147 edits. Asserted against the source because it
     * is a property of the codebase rather than of a render: the day somebody adds
     * `extraRecordLinkAttributes()` to a table to "make the menu work there", the mechanism has been
     * misunderstood and this fails.
     */
    public function test_no_table_declares_its_own_record_key(): void
    {
        $offenders = [];

        foreach ($this->tableSources() as $path => $source) {
            if (str_contains($source, 'data-record-key')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'the record key comes from one global configuration, not from tables');
    }

    /**
     * **The `merge: true` tripwire** — §5.2, and it is deliberately *not* the vacuous test the plan
     * declined to write.
     *
     * The plan asks for a merge regression "the moment a table sets a link attribute of its own", and
     * notes that today none does, so the assertion would be vacuous. This is the other way round: it
     * asserts that none does. Today it passes for a real reason, and the day a table declares
     * `extraRecordLinkAttributes()` it fails and points whoever wrote it at the merge question — which is
     * exactly when the merge regression becomes worth writing.
     *
     * Without `merge: true` in `AppServiceProvider`, that table's attributes would replace ours and the
     * symptom would appear in that table rather than in the provider.
     */
    public function test_no_table_sets_its_own_link_attributes(): void
    {
        $offenders = [];

        foreach ($this->tableSources() as $path => $source) {
            if (str_contains($source, 'extraRecordLinkAttributes')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A table now sets link attributes of its own. Check that AppServiceProvider still merges '
            .'rather than replaces, then add the regression §5.2 asks for and delete this test.',
        );
    }

    /**
     * **A table with no record URL gets no attribute, and that is why the fallback exists.**
     *
     * The Activity Log list calls `recordUrl(null)` deliberately (see its own comments), so it renders no
     * row anchor — and `extraRecordLinkAttributes()` only reaches an anchor
     * (`index.blade.php:1276` and `:2347`). The script parses `wire:key` for exactly this case.
     *
     * Worth a test rather than a comment: the fallback is the kind of branch that rots unnoticed, because
     * every table anybody looks at has the attribute.
     */
    public function test_a_table_without_a_record_url_still_offers_a_parseable_key(): void
    {
        $html = Livewire::test(ListActivityLogs::class)->loadTable()->html();

        $this->assertStringNotContainsString(
            'data-record-key',
            $html,
            'no anchor, so no link attributes — this is the case the wire:key fallback is for',
        );
        $this->assertMatchesRegularExpression(
            '/wire:key="[^"]*\.table\.records\.[^"]+"/',
            $html,
            'and the fallback has something to parse',
        );
    }

    /**
     * Every table class in the application, as source.
     *
     * A source scan rather than a Livewire sweep over 147 resources, and the trade is worth stating: the
     * record key is applied by **one global registration**, so the only way a table can lose it is by
     * declaring link attributes of its own. Scanning for that is exact and instant; booting 147 Livewire
     * components to observe a property that is structurally guaranteed would add well over a minute to
     * the suite to catch nothing this does not.
     *
     * @return array<string, string>
     */
    private function tableSources(): array
    {
        $sources = [];

        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Modules'))
        )) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (! str_contains($path, 'Table') && ! str_contains($path, 'RelationManager')) {
                continue;
            }

            $sources[$path] = (string) file_get_contents($path);
        }

        // A floor, because a scan that found nothing would pass every assertion above it.
        $this->assertGreaterThan(100, count($sources), 'the scan found the application\'s table classes');

        return $sources;
    }

    // ---------------------------------------------------------------- selection and bulk

    /**
     * **The Alpine contract the script calls, pinned against Filament's shipped bundle** — §1.4.
     *
     * The browser smoke run stubs `filamentTable`'s selection API, because a `file://` harness has no
     * Alpine, no Livewire and no server. That stub is only honest while the real component still exposes
     * these three members, so this is the half that checks it does: a Filament upgrade that renames one
     * fails here rather than leaving a stubbed test passing over a feature that has quietly stopped
     * reading the selection.
     *
     * `getSelectedRecordsCount()` is the one that matters most and is easiest to think you can do without.
     * Filament supports selecting every record across every page, and in that mode it tracks
     * **de**selections instead (`isTrackingDeselectedRecords`) — so counting `selectedRecords` ourselves
     * would report a handful where the user had selected four thousand, and the label would lie about what
     * the action is about to touch.
     */
    public function test_filaments_selection_api_still_carries_the_members_we_call(): void
    {
        $bundle = base_path('vendor/filament/tables/dist/index.js');

        $this->assertFileExists($bundle);

        $source = (string) file_get_contents($bundle);

        foreach (['isRecordSelected', 'getSelectedRecordsCount', 'deselectAllRecords'] as $member) {
            $this->assertStringContainsString(
                $member,
                $source,
                "filamentTable no longer exposes {$member}() — resources/js/table-context-menu.js calls it, "
                .'and the smoke harness stubs it, so both would keep passing while the feature stopped '
                .'reading the selection.',
            );
        }

        // And the select-all-across-pages mode this is careful about is real, not imagined.
        $this->assertStringContainsString('isTrackingDeselectedRecords', $source);
    }

    /**
     * **A bulk action is identified by its mount context, not by a class** — §1.4.
     *
     * Filament writes `mountAction('delete', {}, {"table":true,"bulk":true})` on a bulk action, and the
     * toolbar it lives in also holds the reorder trigger, the grouping selector, the column manager and
     * any non-bulk toolbar action — none of which carries that context. This asserts the marker is really
     * in the rendered attribute, because the script's whole discriminator is a string test against it.
     *
     * The `\u0022` is the point of the second assertion: Filament writes the context through `Js::from()`,
     * so those are literal characters in the HTML rather than a browser escape, and the script normalises
     * them before testing. A change to how Filament encodes it would break the match silently — the bulk
     * menu would come back empty and the row menu would show instead, which looks like nothing being
     * wrong.
     */
    public function test_a_bulk_action_carries_the_context_the_script_looks_for(): void
    {
        $this->invoice(Invoice::STATUS_ISSUED, 'CM-BULK');

        $html = Livewire::test(ListInvoices::class)->loadTable()->html();

        $this->assertStringContainsString('fi-ta-header-toolbar', $html, 'the toolbar renders');
        $this->assertStringContainsString(
            'u0022bulk\u0022:true',
            $html,
            'a bulk action still declares itself in its mount context',
        );
    }

    /**
     * Bulk actions are in the DOM before anything is selected, which is why the menu can read them.
     *
     * The toolbar renders them unconditionally and only the *container* is `x-show`n on the selection count
     * (`index.blade.php:347` and `:362-364`). Same eager-render property as a row's `ActionGroup`: it means
     * no dropdown has to be opened and no second visibility decision is made.
     */
    public function test_bulk_actions_are_rendered_before_a_selection_exists(): void
    {
        $this->invoice(Invoice::STATUS_ISSUED, 'CM-BULK-2');

        $html = Livewire::test(ListInvoices::class)->loadTable()->html();

        // Nothing has been selected in this render, and the actions are still there to be read.
        $this->assertStringContainsString("mountAction('delete'", $html);
        $this->assertStringContainsString('x-show="getSelectedRecordsCount()"', $html);
    }

    /**
     * **The label carries the count, and it does not stutter.**
     *
     * §1.4 asks for "Delete 6 selected". Filament's own bulk labels already end in the word — *Delete
     * selected* — so appending naively produced *"Delete selected 2 selected"*, which the first browser run
     * showed. Asserted here as a property of the script rather than of a render, because it is a phrasing
     * rule and phrasing rules are what get quietly undone.
     */
    public function test_the_bulk_label_drops_filaments_trailing_selected(): void
    {
        $source = (string) file_get_contents(resource_path('js/table-context-menu.js'));

        // A literal substring, not a regex: the thing being searched for *is* a regex, and matching one
        // with another cost more escaping than the assertion was worth — the first attempt failed on its
        // own backslashes rather than on the code.
        $this->assertStringContainsString(
            '+selected$/i',
            $source,
            'the trailing "selected" is stripped before the count is appended',
        );
        $this->assertStringContainsString('${count} selected', $source);
    }

    /**
     * **Right-clicking outside a selection clears it**, asserted on the script.
     *
     * The browser run proves the behaviour; this pins the *intent*, because the clearing is the part that
     * looks removable. Leaving a stale selection means the checkboxes still say six while the menu said
     * one, and the next bulk action reached for from the toolbar operates on a selection the user thought
     * they had abandoned.
     */
    public function test_the_script_clears_a_selection_it_does_not_act_on(): void
    {
        $source = (string) file_get_contents(resource_path('js/table-context-menu.js'));

        $this->assertStringContainsString('selection.clear()', $source);
        $this->assertStringContainsString('deselectAllRecords', $source);
    }

    // ---------------------------------------------------------------- keyboard, touch, preference

    /**
     * **The platform's own context-menu keys, and the focus contract around them** — §4.
     *
     * A feature bound to a mouse button with no other route is a feature half the users cannot reach. The
     * browser run proves the keys open the menu; this pins the *intent* in the source, because the pieces
     * that make a keyboard route usable are the ones that look removable — the focus return most of all.
     */
    public function test_the_script_opens_on_the_platform_context_menu_keys(): void
    {
        $source = $this->script();

        $this->assertStringContainsString("'ContextMenu'", $source, 'the dedicated key');
        $this->assertStringContainsString("'F10'", $source, 'and the binding for keyboards without it');

        // Opened for the *focused* row, which is the whole point of the route.
        $this->assertStringContainsString('document.activeElement', $source);

        // Escape must give focus back, or the next Tab starts from the top of the document.
        $this->assertStringContainsString('returnFocusTo', $source);
    }

    /**
     * **A `role="menu"` that names the record**, so a screen reader says what the menu is about — §4.
     *
     * Without the label it announces "menu" and then a list of verbs, and the row it belongs to is
     * whatever the user happened to be on. The label is borrowed from the row's first cell — the thing a
     * sighted user would call the row too.
     */
    public function test_the_menu_names_the_record_it_belongs_to(): void
    {
        $source = $this->script();

        $this->assertStringContainsString("setAttribute('aria-label'", $source);
        $this->assertStringContainsString('Actions for ', $source);
        $this->assertStringContainsString("role', 'menu'", $source);
    }

    /**
     * **The long-press movement threshold, which the plan calls not optional.**
     *
     * "A shop or warehouse tablet scrolls a long list constantly", so a long-press that ignored movement
     * would open a menu every time somebody flicked the list — and open it *mid-scroll*, over whichever row
     * had slid under the finger.
     *
     * The `pointerType` guard matters as much in the other direction: a mouse already has a right button
     * and a pen has a barrel button, so putting half a second in front of either would make both feel
     * broken.
     */
    public function test_the_long_press_is_touch_only_and_has_a_movement_threshold(): void
    {
        $source = $this->script();

        $this->assertStringContainsString('LONG_PRESS_MS = 500', $source, "§4's ≈500 ms");
        $this->assertStringContainsString('LONG_PRESS_SLOP', $source);
        $this->assertStringContainsString("pointerType !== 'touch'", $source);
        $this->assertStringContainsString('pointercancel', $source, 'Chrome taking the gesture over for a scroll');
    }

    /**
     * **A scroll must not cancel a long-press**, which is the opposite of what the first draft did.
     *
     * `closeMenu()` returns focus to the row, and focusing an element scrolls it into view — so a
     * scroll-driven cancel fires on a press that started *after* the scroll it is reacting to. The finger's
     * own `pointermove` and `pointercancel` are the reliable signals and are handled; the scroll listener
     * only closes an open menu.
     *
     * Asserted because the bug is invisible except in a specific order of gestures, which is how the
     * browser run found it: press Escape, then long-press.
     */
    public function test_a_scroll_closes_the_menu_without_cancelling_a_press(): void
    {
        $source = $this->script();

        $this->assertStringContainsString("window.addEventListener('scroll', closeMenu, true)", $source);
        $this->assertStringNotContainsString(
            "window.addEventListener('scroll', () => {
            cancelLongPress()",
            $source,
            'a scroll can be caused by focus rather than by the finger',
        );
    }

    /**
     * **The off switch is `localStorage`, and that is a decision rather than a shortcut** — §4.
     *
     * The plan wants the preference to live "with whatever holds user preferences at that point", naming
     * Phase 7 of `docs/reports-expansion-plan.md` as the obvious home "rather than a second one". That
     * phase has not landed — there is no `dashboard_layouts` table and no per-user store — so building one
     * here would create precisely the second store the plan warns against, which Phase 7 would then have
     * to reconcile with.
     *
     * `localStorage` is client state for a client gesture, which is where this application already keeps
     * the domain rail's open state. Per-device is arguably the better answer too: somebody who wants the
     * menu off on a shop tablet may well want it on at a desk.
     */
    public function test_the_off_switch_uses_the_client_store_and_the_same_key_as_the_toggle(): void
    {
        $script = $this->script();
        $toggle = (string) file_get_contents(
            resource_path('views/filament/partials/table-context-menu-toggle.blade.php')
        );

        $this->assertStringContainsString("DISABLED_KEY = 'tableContextMenuDisabled'", $script);
        $this->assertStringContainsString("'tableContextMenuDisabled'", $toggle, 'the toggle writes the same key');

        // The literal both sides agree on. `$persist` would JSON-encode and they would disagree silently —
        // the domain rail partial documents that same seam.
        $this->assertStringContainsString("=== 'off'", $script);
        $this->assertStringContainsString("'off'", $toggle);
        // The *call*, not the word. The partial's own comment explains why `$persist` is not used, so an
        // assertion against the bare word fails on its own explanation — the third time that shape has
        // caught me on this feature, after `window.open` and a regex matched with a regex.
        $this->assertStringNotContainsString('$persist(', $toggle);

        // Read per gesture, so a toggle in another tab takes effect on the next right-click.
        $this->assertStringContainsString('isTurnedOff()', $script);
    }

    /**
     * **And there is a route back**, which is the part that is easy to leave out.
     *
     * The context menu carries the toggle because that is where somebody annoyed by it will look. But a
     * menu that has just switched itself off cannot switch itself back on, so the user menu carries it
     * too — always reachable, whatever the preference says.
     */
    public function test_the_toggle_is_reachable_when_the_menu_is_off(): void
    {
        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));

        $this->assertStringContainsString('USER_MENU_AFTER', $provider);
        $this->assertStringContainsString('table-context-menu-toggle', $provider);
        $this->assertFileExists(resource_path('views/filament/partials/table-context-menu-toggle.blade.php'));
    }

    /**
     * The toggle partial binds nothing that could stack — §2's rule, applied to a partial that reappears.
     *
     * Alpine's `x-on:click` binds per element on initialise, which is exactly the behaviour wanted on a
     * render-hook partial. A bare `document.addEventListener` in a partial would be the trap §2 documents,
     * and `test_the_menu_ships_no_inline_script` guards the menu itself against it — this guards the
     * toggle.
     */
    public function test_the_toggle_partial_adds_no_document_listener(): void
    {
        $toggle = (string) file_get_contents(
            resource_path('views/filament/partials/table-context-menu-toggle.blade.php')
        );

        $this->assertStringContainsString('x-on:click', $toggle);
        $this->assertStringNotContainsString('document.addEventListener', $toggle);

        // One exception, and it is on `window` rather than `document`, scoped to Alpine's own lifecycle:
        // honouring a change made in another tab.
        $this->assertStringContainsString("window.addEventListener('storage'", $toggle);
    }

    /** The script, read once for the assertions above. */
    private function script(): string
    {
        return (string) file_get_contents(resource_path('js/table-context-menu.js'));
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
