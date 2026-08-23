/**
 * Drives the table context menu in a real browser — docs/table-context-menu-plan.md §5.3.
 *
 * Run it through `php artisan table-context-menu:smoke`, which renders a real table and writes the
 * harness this consumes. Do not run it directly; it needs that file.
 *
 *   node scripts/table-context-menu-smoke.cjs <harness.html>
 *
 * **Why a harness rather than the running application.** The assertions here are about a gesture on
 * markup, and nothing about them needs a session, a database or a server — so this runs with none of
 * those, which means it runs in CI. What it must not do is invent the markup: the harness is the
 * *rendered output of a real Filament table*, dumped by the artisan command, so a Filament upgrade that
 * changes a class name breaks this test rather than sliding past it. That is not hypothetical — Phase 1
 * found `.fi-ta-record` matched nothing, because the ordinary table layout renders `.fi-ta-row`.
 *
 * Puppeteer is launched `headless: 'shell'` with `--disable-gpu`, matching scripts/screenshot-help.cjs:
 * under the default headless mode this application's pages misbehave in ways that are the mode's fault
 * rather than the page's.
 */
const harness = process.argv[2];
const table = process.argv[3] ?? 'invoices';

if (!harness) {
  console.error('Usage: node scripts/table-context-menu-smoke.cjs <harness.html>');
  process.exit(2);
}

(async () => {
  const puppeteer = (await import('puppeteer')).default;

  const browser = await puppeteer.launch({
    headless: 'shell',
    args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
  });

  const page = await browser.newPage();
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await page.goto('file://' + harness, { waitUntil: 'domcontentloaded' });

  const result = await page.evaluate(async () => {
    const rows = Array.from(document.querySelectorAll('.fi-ta-row, .fi-ta-record'));
    const menu = () => document.querySelector('.fi-ta-context-menu');
    const isOpen = () => !!menu() && !menu().hidden;
    const labels = () =>
      Array.from(menu()?.querySelectorAll('.fi-ta-context-menu-item') ?? []).map((el) =>
        el.textContent.trim(),
      );

    /*
     * The menu's items minus the preference one.
     *
     * Phase 4 appends "Turn off right-click menus" to every menu, and it carries no selection count and is
     * not one of the row's actions — so the assertions about *actions* have to exclude it rather than be
     * relaxed to accommodate it.
     */
    const PREFERENCE_ITEM = 'Turn off right-click menus';
    const actionLabels = () => labels().filter((label) => label !== PREFERENCE_ITEM);

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    /** A real contextmenu event at the row's own coordinates. Returns true if we suppressed the native menu. */
    const rightClick = (row, init = {}) => {
      const cell = row.querySelector('.fi-ta-col, .fi-ta-record-content') ?? row;
      const box = cell.getBoundingClientRect();

      return !cell.dispatchEvent(
        new MouseEvent('contextmenu', {
          bubbles: true,
          cancelable: true,
          clientX: box.left + 5,
          clientY: box.top + 5,
          ...init,
        }),
      );
    };

    const escape = () =>
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    const out = { rows: rows.length };

    /*
     * Phase 2's record key, in the DOM the script actually reads.
     *
     * Both routes are checked because the script prefers the attribute and parses `wire:key` when there
     * is none: if the two ever disagreed, the row identified would depend on which ran. A table with no
     * record URL legitimately has only the second — hence `hasAttribute` is reported rather than
     * asserted, and the agreement is what matters.
     */
    const first = rows[0];
    const attributeKey = first.querySelector('[data-record-key]')?.dataset.recordKey ?? null;
    const wireKey = (first.getAttribute('wire:key') ?? '').split('.table.records.')[1] ?? null;

    out.hasRecordKeyAttribute = attributeKey !== null;
    out.recordKeysAgree = attributeKey === null || attributeKey === wireKey;

    // The row's own actions, where the cursor is.
    out.suppressedNative = rightClick(rows[0]);
    out.opened = isOpen();
    out.firstRowItems = actionLabels();
    out.hasPreferenceItem = labels().includes(PREFERENCE_ITEM);
    out.newTabIsRealAnchor = !!menu()?.querySelector('a[target="_blank"]');

    escape();
    out.escapeCloses = !isOpen();

    // The escape hatch. Firefox does this natively; Chromium does not, so the script must.
    out.shiftYieldsNative = !rightClick(rows[0], { shiftKey: true }) && !isOpen();

    // Per-record correctness: two rows of one table, different menus. The whole thesis of the design.
    rightClick(rows[1]);
    out.secondRowItems = actionLabels();

    // Off a row, the browser keeps its own menu.
    //
    // `dispatchEvent` returns **false** when a listener called `preventDefault()`, so "yields native" is
    // the un-negated return. The first draft of this line negated it and reported a pass as a failure —
    // worth the comment, because `rightClick()` above deliberately negates for the opposite question.
    escape();
    out.outsideYieldsNative = document.body.dispatchEvent(
      new MouseEvent('contextmenu', { bubbles: true, cancelable: true }),
    );

    // One element for the page however many times it opens — never one per row.
    rows.forEach((row) => rightClick(row));
    out.menuElements = document.querySelectorAll('.fi-ta-context-menu').length;

    // A menu left floating over swapped content would mount actions against a component that is gone.
    document.dispatchEvent(new Event('livewire:navigated'));
    out.navigationCloses = !isOpen();

    /*
     * §1.4 — selection wins over the row, and right-clicking outside a selection clears it.
     *
     * The selection is arranged through the harness's Alpine stub (see the artisan command for what that
     * stands in for and what pins the real contract). Keys come from the rows themselves rather than being
     * hardcoded, so this follows the fixture rather than assuming its ids.
     */
    const keyOf = (row) =>
      row.querySelector('[data-record-key]')?.dataset.recordKey ??
      (row.getAttribute('wire:key') ?? '').split('.table.records.')[1];

    // Both rows selected: the menu must be the bulk one, and every label must carry the count.
    escape();
    window.__selection = new Set(rows.map(keyOf));
    rightClick(rows[0]);
    out.bulkItems = actionLabels();
    out.bulkMenuIsMarked = !!menu()?.classList.contains('fi-ta-context-menu-bulk');
    out.bulkLabelsCarryTheCount = out.bulkItems.length > 0 && out.bulkItems.every((label) => label.endsWith('2 selected'));
    out.bulkMenuOffersNoRowActions = !out.bulkItems.includes('Edit');
    out.bulkMenuOffersNoLinks = !out.bulkItems.includes('Open in new tab');

    // One row selected, right-click the *other*: the selection is abandoned and the row's own menu shows.
    escape();
    window.__selection = new Set([keyOf(rows[0])]);
    rightClick(rows[1]);
    out.outsideSelectionItems = actionLabels();
    out.outsideSelectionClearedIt = window.__selection.size === 0;
    out.outsideSelectionShowsTheRow = out.outsideSelectionItems.includes('Open in new tab');

    // And with nothing selected at all, nothing changes from the plain row menu.
    escape();
    window.__selection = new Set();
    rightClick(rows[0]);
    out.unselectedItems = actionLabels();

    /*
     * §5 — grouped sections, and the Copy section.
     *
     * The heading names the `ActionGroup` the items below it came from, mirroring the row's own grouping.
     * Reported rather than asserted here because only one table in the application has such a group; the
     * runner decides what to require based on which table it was given.
     */
    out.headings = Array.from(menu().querySelectorAll('.fi-ta-context-menu-heading')).map((el) =>
      el.textContent.trim(),
    );
    out.separatorCount = menu().querySelectorAll('.fi-ta-context-menu-separator').length;
    out.namedSeparators = Array.from(menu().querySelectorAll('.fi-ta-context-menu-separator[aria-label]')).map(
      (el) => el.getAttribute('aria-label'),
    );
    out.headingsAreHiddenFromAt = Array.from(
      menu().querySelectorAll('.fi-ta-context-menu-heading'),
    ).every((el) => el.getAttribute('aria-hidden') === 'true');
    out.copyItems = out.unselectedItems.filter((label) => label.startsWith('Copy'));
    out.noLeadingSeparator = !menu().firstElementChild?.classList.contains('fi-ta-context-menu-separator');

    /*
     * §4 — the keyboard route.
     *
     * The platform's own context-menu keys, opening for the **focused** row rather than a hovered one,
     * which is the whole point for somebody not using a mouse.
     */
    escape();
    const cell = rows[1].querySelector('.fi-ta-col');
    cell.setAttribute('tabindex', '-1');
    cell.focus();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ContextMenu', bubbles: true }));
    out.contextMenuKeyOpens = isOpen();
    out.keyboardMenuNamesTheRow = (menu()?.getAttribute('aria-label') ?? '').startsWith('Actions for');

    // Escape must hand focus back, or the keyboard route is a trap.
    escape();
    out.focusReturnedToTheCell = document.activeElement === cell;

    cell.focus();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'F10', shiftKey: true, bubbles: true }));
    out.shiftF10Opens = isOpen();

    // Arrow and Home/End move between items.
    const firstItem = document.activeElement;
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    out.arrowMovesFocus = document.activeElement !== firstItem;
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    // The *last* item, not `:last-of-type` — that pseudo-class is per element type, and the menu mixes
    // buttons with anchors, so it matched the last button rather than the last item. My selector, not the
    // code.
    const menuItems = Array.from(menu().querySelectorAll('.fi-ta-context-menu-item'));
    out.endGoesToTheLastItem = document.activeElement === menuItems[menuItems.length - 1];
    escape();

    /*
     * §4 — touch long-press, and the movement threshold that makes it usable on a list somebody scrolls.
     */
    const touch = (type, target, dx = 0, dy = 0) => {
      const box = (target.querySelector('.fi-ta-col') ?? target).getBoundingClientRect();

      target.dispatchEvent(
        new PointerEvent(type, {
          bubbles: true,
          cancelable: true,
          pointerType: 'touch',
          isPrimary: true,
          clientX: box.left + 5 + dx,
          clientY: box.top + 5 + dy,
        }),
      );
    };

    touch('pointerdown', rows[0]);
    await sleep(150);
    out.longPressNotYetOpen = !isOpen();
    await sleep(500);
    out.longPressOpens = isOpen();
    escape();

    // A finger that wanders is a scroll, not a press.
    touch('pointerdown', rows[0]);
    touch('pointermove', rows[0], 0, 40);
    await sleep(650);
    out.scrollCancelsLongPress = !isOpen();

    /*
     * §4 — the off switch. Last, because it turns the feature off for everything after it.
     */
    escape();
    rightClick(rows[0]);
    const off = Array.from(menu().querySelectorAll('.fi-ta-context-menu-item')).find(
      (el) => el.textContent.trim() === PREFERENCE_ITEM,
    );
    off.click();

    out.offSwitchStored = localStorage.getItem('tableContextMenuDisabled') === 'off';
    out.offSwitchClosedTheMenu = !isOpen();
    out.turnedOffYieldsNative = !rightClick(rows[0]) && !isOpen();

    // And it is read per gesture, not cached: clearing it brings the menu straight back.
    localStorage.removeItem('tableContextMenuDisabled');
    out.turningItBackOnWorks = rightClick(rows[0]) && isOpen();
    escape();

    return out;
  });

  await browser.close();

  const failures = [];
  const expect = (name, condition, detail) => {
    if (!condition) {
      failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
    }
  };

  expect('rows found', result.rows >= 2, `found ${result.rows}; the harness needs at least two`);
  expect('the menu opens on right-click', result.opened);
  expect('the native menu is suppressed', result.suppressedNative);
  expect('the row carries link items', result.firstRowItems.includes('Open in new tab'));
  expect('new tab is a real anchor', result.newTabIsRealAnchor);
  expect('the row carries its own actions', result.firstRowItems.length > 3);
  expect('Escape closes', result.escapeCloses);
  expect('Shift yields the native menu', result.shiftYieldsNative);
  expect('right-click off a row yields the native menu', result.outsideYieldsNative);
  expect(
    'two rows offer different menus',
    JSON.stringify(result.firstRowItems) !== JSON.stringify(result.secondRowItems),
    'per-record filtering is the point of the feature',
  );
  expect('one menu element for the page', result.menuElements === 1, `found ${result.menuElements}`);
  expect('navigation closes the menu', result.navigationCloses);
  expect(
    'the record key attribute reaches the row',
    result.hasRecordKeyAttribute,
    'Table::configureUsing() should have applied it — see AppServiceProvider',
  );
  expect(
    'the attribute and the wire:key name the same record',
    result.recordKeysAgree,
    'one fallback is only safe while both routes agree',
  );

  // §1.4
  expect('a selection produces a bulk menu', result.bulkItems.length > 0);
  expect('the bulk menu is marked as one', result.bulkMenuIsMarked);
  expect(
    'every bulk label carries the count',
    result.bulkLabelsCarryTheCount,
    `got ${JSON.stringify(result.bulkItems)} — a label saying "Delete" over six selected rows is how somebody deletes five too many`,
  );
  expect('the bulk menu offers no row actions', result.bulkMenuOffersNoRowActions);
  expect('the bulk menu offers no link items', result.bulkMenuOffersNoLinks, 'a selection has no single URL');
  expect(
    'right-clicking outside a selection clears it',
    result.outsideSelectionClearedIt,
    'a stale selection means the checkboxes and the menu disagree',
  );
  expect('and shows that row\'s own menu', result.outsideSelectionShowsTheRow);
  expect(
    'no selection behaves exactly as before',
    JSON.stringify(result.unselectedItems) === JSON.stringify(result.firstRowItems),
    'Phase 3 must not change the unselected case',
  );

  // §4
  expect('every menu carries the preference item', result.hasPreferenceItem);
  expect('the ContextMenu key opens the menu', result.contextMenuKeyOpens);
  expect('Shift+F10 opens the menu', result.shiftF10Opens);
  expect('the menu names the record for a screen reader', result.keyboardMenuNamesTheRow);
  expect(
    'Escape returns focus to where it came from',
    result.focusReturnedToTheCell,
    'without this the keyboard route is a trap — the next Tab starts from the top of the document',
  );
  expect('arrow keys move between items', result.arrowMovesFocus);
  expect('End goes to the last item', result.endGoesToTheLastItem);
  expect('a long-press does not fire early', result.longPressNotYetOpen);
  expect('a long-press opens the menu', result.longPressOpens);
  expect(
    'a wandering finger cancels the long-press',
    result.scrollCancelsLongPress,
    'a tablet scrolls a long list constantly; without this the menu opens mid-scroll',
  );
  expect('the off switch stores the preference', result.offSwitchStored);
  expect('the off switch closes the menu', result.offSwitchClosedTheMenu);
  expect('turned off, the gesture yields the native menu', result.turnedOffYieldsNative);
  expect(
    'the preference is read per gesture, not cached',
    result.turningItBackOnWorks,
    'a toggle in another tab should take effect on the next right-click',
  );

  // §5
  expect('no separator leads the menu', result.noLeadingSeparator, 'a rule at the top looks like a fault');
  expect(
    'the Copy section offers link, id and name',
    result.copyItems.length === 3,
    `got ${JSON.stringify(result.copyItems)}`,
  );
  expect('every heading is hidden from assistive tech', result.headingsAreHiddenFromAt);

  if (table === 'projects') {
    expect(
      'an ActionGroup becomes a named section',
      result.headings.length > 0,
      'the projects table has the only record-level ActionGroup in the application — if this is empty, '
        + 'either the group stopped rendering or the fixture stopped creating environments',
    );
    expect(
      'the section name is announced by its separator',
      result.namedSeparators.length > 0,
      'a labelled separator is how the group name reaches a screen reader without a non-menuitem child',
    );
  } else {
    expect(
      'a table with no groups produces no headings',
      result.headings.length === 0,
      `got ${JSON.stringify(result.headings)}`,
    );
  }
  expect('no page errors', pageErrors.length === 0, pageErrors.join(' | '));

  console.log(JSON.stringify({ result, pageErrors, failures }, null, 2));

  if (failures.length) {
    console.error('\nFAILED:\n - ' + failures.join('\n - '));
    process.exit(1);
  }

  console.log('\nOK — every context menu behaviour held.');
})();
