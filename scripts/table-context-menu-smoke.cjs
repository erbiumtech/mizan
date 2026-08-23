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

  const result = await page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('.fi-ta-row, .fi-ta-record'));
    const menu = () => document.querySelector('.fi-ta-context-menu');
    const isOpen = () => !!menu() && !menu().hidden;
    const labels = () =>
      Array.from(menu()?.querySelectorAll('.fi-ta-context-menu-item') ?? []).map((el) =>
        el.textContent.trim(),
      );

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

    // The row's own actions, where the cursor is.
    out.suppressedNative = rightClick(rows[0]);
    out.opened = isOpen();
    out.firstRowItems = labels();
    out.newTabIsRealAnchor = !!menu()?.querySelector('a[target="_blank"]');

    escape();
    out.escapeCloses = !isOpen();

    // The escape hatch. Firefox does this natively; Chromium does not, so the script must.
    out.shiftYieldsNative = !rightClick(rows[0], { shiftKey: true }) && !isOpen();

    // Per-record correctness: two rows of one table, different menus. The whole thesis of the design.
    rightClick(rows[1]);
    out.secondRowItems = labels();

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
  expect('no page errors', pageErrors.length === 0, pageErrors.join(' | '));

  console.log(JSON.stringify({ result, pageErrors, failures }, null, 2));

  if (failures.length) {
    console.error('\nFAILED:\n - ' + failures.join('\n - '));
    process.exit(1);
  }

  console.log('\nOK — every context menu behaviour held.');
})();
