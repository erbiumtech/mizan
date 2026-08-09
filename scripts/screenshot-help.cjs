/**
 * Screenshots every help topic's page for the in-app help panel.
 *
 * Driven by `php artisan help:screenshots` — that command owns resolving the
 * URLs, starting a server with the debug bar off and cleaning up afterwards.
 * Run it, not this file.
 *
 *   node scripts/screenshot-help.cjs <manifest.json> <outDir> <resultsPath>
 *
 * The manifest is {slug: {url, title}}, already absolute against the server the
 * command started.
 *
 * Puppeteer is launched as `headless: 'shell'` with --disable-gpu on purpose:
 * under the default new headless mode Page.captureScreenshot times out on this
 * app while navigation and evaluate() still work, so a capture that hangs here
 * is the headless mode, not a slow page.
 */
const fs = require('fs');
const path = require('path');

const [manifestPath, outDir, resultsPath] = process.argv.slice(2);
const EMAIL = process.env.HELP_SHOT_EMAIL;
const PASSWORD = process.env.HELP_SHOT_PASSWORD;
const BASE = process.env.HELP_SHOT_BASE;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const puppeteer = (await import('puppeteer')).default;
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

  const browser = await puppeteer.launch({
    headless: 'shell',
    protocolTimeout: 120000,
    args: [
      '--no-sandbox',
      '--disable-gpu',
      '--disable-dev-shm-usage',
      '--hide-scrollbars',
      '--force-color-profile=srgb',
    ],
  });

  const page = await browser.newPage();
  // 1x deliberately: these render into a slide-over a few hundred pixels wide,
  // and 2x would quadruple what is already several megabytes of PNG in the repo.
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

  // Filament's login fields are named form.email / form.password, and its
  // Livewire redirect does not reliably satisfy waitForNavigation — hence the
  // fixed wait rather than waiting on navigation.
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[id="form.email"]');
  await page.type('input[id="form.email"]', EMAIL);
  await page.type('input[id="form.password"]', PASSWORD);
  await page.keyboard.press('Enter');
  await sleep(3000);

  const results = { captured: [], skipped: [] };

  for (const [slug, info] of Object.entries(manifest)) {
    try {
      const response = await page.goto(info.url, { waitUntil: 'networkidle2', timeout: 30000 });
      const status = response ? response.status() : 0;

      // A 403/404/500 renders a bare error page. Shipping that as a "screenshot"
      // documents a broken screen for a working feature, so skip it and say so
      // rather than writing the file.
      const body = await page.evaluate(() => document.body.innerText.slice(0, 120).trim());
      if (status !== 200 || /^(403|404|419|500|Forbidden|Not Found|Server Error)/i.test(body)) {
        results.skipped.push({ slug, reason: `HTTP ${status}` });
        continue;
      }

      // Strip the Laravel debug bar out of the page before capturing.
      //
      // It has to happen here rather than by disabling it on the server: the
      // command starts `artisan serve` with DEBUGBAR_ENABLED=false, but
      // ServeCommand rebuilds the child server's environment from $_ENV and
      // filters it through its own pass-through allowlist, so the variable never
      // arrives. The bar is dev-only chrome rather than part of the product, and
      // removing one fixed overlay from a live page leaves the Livewire-rendered
      // content it sits on top of untouched.
      await page.evaluate(() => {
        document
          .querySelectorAll('.phpdebugbar, #phpdebugbar, .phpdebugbar-openhandler, .phpdebugbar-backdrop')
          .forEach((el) => el.remove());
        document.body.style.paddingBottom = '';
      });

      const debugBarRemains = await page.evaluate(
        () => !!document.querySelector('.phpdebugbar, #phpdebugbar')
      );
      if (debugBarRemains) {
        results.skipped.push({ slug, reason: 'debug bar could not be removed' });
        continue;
      }

      await sleep(600);
      await page.screenshot({ path: path.join(outDir, `${slug}.png`) });
      results.captured.push(slug);
    } catch (e) {
      results.skipped.push({ slug, reason: e.message });
    }
  }

  await browser.close();
  fs.writeFileSync(resultsPath, JSON.stringify(results, null, 2));
})().catch((e) => {
  console.error(e.stack || e.message);
  process.exit(1);
});
